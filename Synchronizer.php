<?php
namespace App\Babel\Extension\hdu;

use App\Babel\Crawl\CrawlerBase;
use App\Babel\Submit\Curl;
use App\Models\ProblemModel;
use App\Models\ContestModel;
use App\Models\OJModel;
use Requests;
use Exception;
use Cache;

class Synchronizer extends CrawlerBase implements CurlInterface
{
    public $oid=null;
    public $vcid=null;
    public $gid=null;
    public $prefix="HDU";
    private $con;
    private $imgi;
    private $problemSet = [];
    private $noj_cid;

    public function __construct($all_data) {
        $this->oid=OJModel::oid('hdu');
        $this->vcid=$all_data['vcid'];
        $this->gid=$all_data['gid'];
        $this->noj_cid = isset($all_data['cid'])?$all_data['cid']:null;
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
        if(!$dom) return $dom;
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

        $res = Requests::get("http://acm.hdu.edu.cn/contests/contest_show.php?cid=".$this->vcid);
        $res->body = iconv("gb2312","utf-8//IGNORE",$res->body);
        if (strpos("Sign In Your Account",$res->body) !== false) {
            throw new Exception("Contest not public.");
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
        if(!isset($this->noj_cid)) {
            throw new Exception("No such contest");
            return false;
        }
        $startId = 1;
        while($this->_clarification($startId)) {
            $startId++;
        }
    }

    public function _clarification($id) 
    {
        $res = Requests::get("http://acm.hdu.edu.cn/viewnotify.php?id={$id}&cid=".$this->vcid);
        $res->body = iconv("gb2312","utf-8//IGNORE",$res->body);
        if(strpos($res->body,"No such notification.") != false) {
            return false;
        } else {
            $contestModel = new ContestModel();
            $title = self::find("/<strong>([\s\S]*?)<\/strong>/",$res->body);
            $contentRaw = self::find("/Time : ([\s\S]*?)Posted/",$res->body);
            $pos = strpos($contentRaw,"<\div>",-1);
            $content = trim(strip_tags(substr($contentRaw,$pos)));
            $contestModel->issueAnnouncement($this->noj_cid,$title,$content,1);
        }
    }

    public function crawlRank() {
        if(!isset($this->noj_cid)) {
            throw new Exception("No such contest");
            return false;
        }
        $contestModel = new ContestModel();
        $res = Requests::get("http://acm.hdu.edu.cn/contests/contest_ranklist.php?cid=".$this->vcid."&page=1");
        preg_match_all('/<a href=[\s\S]*?style="display: inline-block; padding: 5px 8px; font-weight: bold;">([\d+]*?)<\/a>/',$res->body,$matches);
        $totalPageNumber = sizeof($matches[1]) / 2;
        $it = 1;
        $rank = [];
        $problemst = $contestModel->contestProblems($this->noj_cid,1);
        $problemNumber = sizeof($problemst);
        while($it <= $totalPageNumber) {
            $ret = Requests::get("http://acm.hdu.edu.cn/contests/contest_ranklist.php?cid=".$this->vcid."&page={$it}");
            $pattern = "/<td>([\s\S]*?)<\/td>";
            for($i = 1;$i <=3; $i++) {
                $pattern .= "[\s\s]*?<td>([\s\S]*?)<\/td>";
            }
            for($i = 1;$i < $problemNumber;$i++) {
                $pattern .= "[\s\S]*?<td[\s\S]*?>([\s\S]*?)<\/td>";
            }
            $pattern .= "[\s\S]*?<td[\s\S]*?>([\s\S]*?)<\/td>/";
            preg_match_all($pattern,$ret->body,$matches);
            $teamNumber = sizeof($matches[1]) - 1;
            for($i = 1; $i <= $teamNumber; $i++) {
                $team = [];
                $team['uid'] = null;
                $team['name'] = $matches[2][$i];
                $team['nickname'] = null;
                $team['score'] = $matches[3][$i];
                $zerotime = "00:00:00";
                $team['penalty'] = floor(strtotime($matches[4][$i]) - strtotime($zerotime))%86400/60;
                $problems = [];
                for($j = 1; $j <= $problemNumber; $j++) {
                    $prob = [];
                    $prob['ncode'] = $j + 1000;
                    $prob['pid'] = $problemst[$j-1]['pid'];
                    if($matches[$j+4][$i]!="&nbsp;") {
                        $prob['color'] = "wemd-green-text";
                        $startM = strpos($matches[$j+4][$i],"-");
                        $endM = strpos($matches[$j+4][$i],")");
                        $prob["wrong_doings"] = intval(substr($matches[$j+4][$i],$startM+1,$endM-$startM-1));
                        if(!$prob["wrong_doings"]){
                            $prob["solved_time_parsed"] = trim($matches[$j+4][$i]);
                        } else {
                            $prob["solved_time_parsed"] = trim(strip_tags(substr($matches[$j+4][$i],0,$startM-1)));
                        }
                    } else {
                        $prob['color'] = "";
                        $prob["wrong_doings"] = 0;
                        $prob["solved_time_parsed"] = "";
                    }
                    array_push($problems,$prob);
                }
                $team['problem_detail'] = $problems;
                array_push($rank,$team);
            }
        }
        $contestRankRaw = $contestModel->contestRankCache($this->noj_cid);
        $rankFinal = array_merge($rank,$contestRankRaw);
        usort($rankFinal, function ($a, $b) {
            if ($a["score"]==$b["score"]) {
                if ($a["penalty"]==$b["penalty"]) {
                    return 0;
                } elseif (($a["penalty"]>$b["penalty"])) {
                    return 1;
                } else {
                    return -1;
                }
                } elseif ($a["score"]>$b["score"]) {
                    return -1;
                } else {
                    return 1;
                }
        });
        Cache::tags(['contest', 'rank'])->put($this->noj_cid, $rankFinal, 60);
    }
}