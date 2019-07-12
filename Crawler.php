<?php
namespace App\Babel\Extension\hdu;

use App\Babel\Crawl\CrawlerBase;
use App\Models\ProblemModel;
use App\Models\OJModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;

class Crawler extends CrawlerBase
{
    public $oid=null;
    public $prefix="HDU";
    private $con;
    private $imgi;

    /**
     * Initial
     *
     * @return Response
     */
    public function __construct($conf)
    {
        $action=isset($conf["action"])?$conf["action"]:'crawl_problem';
        $con=isset($conf["con"])?$conf["con"]:'all';
        $cached=isset($conf["cached"])?$conf["cached"]:false;
        $this->oid=OJModel::oid('hdu');

        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }
        if ($action=='judge_level') {
            $this->judge_level();
        } else {
            $this->crawl($con);
        }
    }

    public function judge_level()
    {
        // TODO
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

    public function crawl($con)
    {
        if($con == "all") {
            return ;
        }
        $this->con = $con;
        $this->imgi = 1;
        $problemModel = new ProblemModel();
        $res = Requests::get("http://acm.hdu.edu.cn/showproblem.php?pid={$con}");
        if (strpos("No such problem",$res->body) !== false) {
            header('HTTP/1.1 404 Not Found');
            die();
        }
        if(strpos("Invalid Parameter.",$res->body) !== false) {
            header('HTTP/1.1 404 Not Found');
            die();
        }
        $res->body = iconv("gb2312","utf-8//IGNORE",$res->body);
        $this->pro['pcode'] = $this->prefix.$con;
        $this->pro['OJ'] = $this->oid;
        $this->pro['contest_id'] = null;
        $this->pro['index_id'] = $con;
        $this->pro['origin'] = "http://acm.hdu.edu.cn/showproblem.php?pid={$con}";
        
        $this->pro['title'] = self::find("/<h1 style='color:#1A5CC8'>([\s\S]*?)<\/h1>/",$res->body);
        $this->pro['time_limit'] = self::find('/Time Limit:.*\/(.*) MS/',$res->body);
        $this->pro['memory_limit'] = self::find('/Memory Limit:.*\/(.*) K/',$res->body);
        $this->pro['solved_count'] = self::find("/Accepted Submission(s): ([\d+]*?)/",$res->body);
        $this->pro['input_type'] = 'standard input';
        $this->pro['output_type'] = 'standard output';
        
        $this->pro['description'] = $this->cacheImage(HtmlDomParser::str_get_html(self::find("/Problem Description.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res->body), true, true, DEFAULT_TARGET_CHARSET, false));
        $this->pro['description'] = str_replace("$", "$$", $this->pro['description']);
        $this->pro['input'] = self::find("/<div class=panel_title align=left>Input.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res->body);
        $this->pro['input'] = str_replace("$", "$$", $this->pro['input']);
        $this->pro['output'] = self::find("/<div class=panel_title align=left>Output.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res->body);
        $this->pro['output'] = str_replace("$", "$$", $this->pro['output']);
        $this->pro['sample'] = [];
        $this->pro['sample'][] = [
            'sample_input'=>self::find("/<pre><div.*>(.*)<\/div><\/pre>/sU",$res->body),
            'sample_output'=>self::find("/<div.*>Sample Output<\/div><div.*><pre><div.*>(.*)<\/div><\/pre><\/div>/sU",$res->body)
        ];
        $this->pro['note'] = self::find("/<i>Hint<\/i><\/div>(.*)<\/div><i style='font-size:1px'>/sU",$res->body);
        $this->pro['source'] = strip_tags(self::find("/<div class=panel_title align=left>Source<\/div> (.*)<div class=panel_bottom>/sU",$res->body));
            
        if($this->pro['source'] === "") {
            $this->pro['source'] = $this->pro['pcode'];
        }
        $problem=$problemModel->pid($this->pro['pcode']);

        if ($problem) {
            $problemModel->clearTags($problem);
            $new_pid=$this->update_problem($this->oid);
        } else {
            $new_pid=$this->insert_problem($this->oid);
        }
    }
}