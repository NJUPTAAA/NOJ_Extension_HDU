<?php
namespace App\Babel\Extension\hdu;

use App\Babel\Crawl\CrawlerBase;
use App\Babel\Submit\Curl;
use App\Models\ProblemModel;
use App\Models\ContestModel;
use App\Models\OJModel;
use Requests;
use Exception;

class Synchronizer extends CrawlerBase implements CurlInterface
{
    public $oid=null;
    public $vcid=null;
    public $gid=null;
    public $prefix="HDU";
    private $con;
    private $imgi;
    private $problemSet = [];

    public function __construct($all_data) {
        $this->oid=OJModel::oid('hdu');
        $this->vcid=$all_data['cid'];
        $this->gid=$all_data['gid'];
    }

    private static function find($pattern, $subject)
    {
        if (preg_match($pattern, $subject, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function cacheImage($dom)
    {
        foreach ($dom->find('img') as $ele) {
            $src=str_replace('../../..', '', $ele->src);
            if (strpos($src, '://')!==false) {
                $url=$src;
            } elseif ($src[0]=='/') {
                $url='http://acm.hdu.edu.cn'.$src;
            } else {
                $url='http://acm.hdu.edu.cn/'.$src;
            }
            $res=Requests::get($url, ['Referer' => 'http://acm.hdu.edu.cn']);
            $ext=['image/jpeg'=>'.jpg', 'image/png'=>'.png', 'image/gif'=>'.gif', 'image/bmp'=>'.bmp'];
            if (isset($res->headers['content-type'])) {
                $cext=$ext[$res->headers['content-type']];
            } else {
                $pos=strpos($ele->src, '.');
                if ($pos===false) {
                    $cext='';
                } else {
                    $cext=substr($ele->src, $pos);
                }
            }
            $fn=$this->con.'_'.($this->imgi++).$cext;
            $dir=base_path("public/external/hdu/img");
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(base_path("public/external/hdu/img/$fn"), $res->body);
            $ele->src='/external/hdu/img/'.$fn;
        }
        return $dom;
    }

    public function crawlProblem($con)
    {
        if($con == "all") {
            return ;
        }
        $this->con = $con;
        $this->imgi = 1;
        $problemModel = new ProblemModel();
        $res = Requests::get("http://acm.hdu.edu.cn/contests/contest_showproblem.php?pid={$con}&cid=".$this->vcid);
        if (strpos("No such problem",$res->body) !== false) {
            return false;
        }
        if(strpos("Invalid Parameter.",$res->body) !== false) {
            return false;
        }
        $res->body = iconv("gb2312","utf-8//IGNORE",$res->body);
        $pro = [];
        $pro['pcode'] = $this->prefix.$vcid."-".$con;
        $pro['OJ'] = $this->oid;
        $pro['contest_id'] = $vcid; //TODO Clarify virtual and NOJ contest.
        $pro['index_id'] = $con;
        $pro['origin'] = "http://acm.hdu.edu.cn/contests/contest_showproblem.php?pid={$con}&cid=".$this->vcid;
        
        $pro['title'] = self::find("/<h1 style='color:#1A5CC8'>([\s\S]*?)<\/h1>/",$res->body);
        $pro['time_limit'] = self::find('/Time Limit:.*\/(.*) MS/',$res->body);
        $pro['memory_limit'] = self::find('/Memory Limit:.*\/(.*) K/',$res->body);
        $pro['solved_count'] = self::find("/Accepted Submission(s): ([\d+]*?)/",$res->body);
        $pro['input_type'] = 'standard input';
        $pro['output_type'] = 'standard output';
        
        $pro['description'] = $this->cacheImage(HtmlDomParser::str_get_html(self::find("/Problem Description.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res->body), true, true, DEFAULT_TARGET_CHARSET, false));
        $pro['description'] = str_replace("$", "$$", $pro['description']);
        $pro['input'] = self::find("/<div class=panel_title align=left>Input.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res->body);
        $pro['input'] = str_replace("$", "$$", $pro['input']);
        $pro['output'] = self::find("/<div class=panel_title align=left>Output.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res->body);
        $pro['output'] = str_replace("$", "$$", $pro['output']);
        $pro['sample'] = [];
        $pro['sample'][] = [
            'sample_input'=>self::find("/<pre><div.*>(.*)<\/div><\/pre>/sU",$res->body),
            'sample_output'=>self::find("/<div.*>Sample Output<\/div><div.*><pre><div.*>(.*)<\/div><\/pre><\/div>/sU",$res->body)
        ];
        $pro['note'] = self::find("/<i>Hint<\/i><\/div>(.*)<\/div><i style='font-size:1px'>/sU",$res->body);
        $pro['source'] = strip_tags(self::find("/<div class=panel_title align=left>Source<\/div> (.*)<div class=panel_bottom>/sU",$res->body));
            
        if($pro['source'] === "") {
            $pro['source'] = $pro['pcode'];
        }
        $problem=$problemModel->pid($pro['pcode']);

        if ($problem) {
            $problemModel->clearTags($problem);
            $new_pid=$this->updateProblem($this->oid);
            $pro['pid'] = $new_pid;
        } else {
            $new_pid=$this->insertProblem($this->oid);
            $pro['pid'] = $new_pid;
        }
        array_push($this->problemSet, $pro);
    }

    public function crawlContest() {
        $contestModel = new ContestModel();

        $res = Requests::get("http://acm.hdu.edu.cn/contests/contest_show.php?cid={$vcid}");
        if (strpos("Sign In Your Account",$res->body) !== false) {
            header('HTTP/1.1 404 Not Found');
            die();
        }
        $contestInfo = [];
        $contestInfo['name'] = self::find('/<h1 style="color:#1a5cc8;margin-top: 20px" align="center">([\s\S]*?)<\/h1>/',$res->body);
        $contestInfo['begin_time'] = self::find('/Start Time : .*\/(.*)&/',$res->body);
        $contestInfo['end_time'] = self::find('/End Time : .*\/(.*)"/',$res->body);
        $contestInfo["description"] = "";
        $contestInfo["vcid"] = $vcid;

        $iteratorID = 1001;
        while(!crawlProblem($iteratorID++)){}
        $noj_cid = $contestModel->arrangeContest($gid, $contestInfo, $this->problemSet);
    }

    public function crawlClarification() {

    }

    public function crawlRank() {

    }
}