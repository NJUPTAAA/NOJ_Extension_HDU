<?php
namespace App\Babel\Extension\hdu;

use App\Babel\Crawl\CrawlerBase;
use App\Babel\Submit\Curl;
use App\Models\ProblemModel;
use App\Models\ContestModel;
use App\Models\JudgerModel;
use App\Models\OJModel;
use App\Models\GroupModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;
use Cache;
use Log;

class Synchronizer extends CrawlerBase
{
    public $oid=null;
    public $vcid=null;
    public $gid=null;
    public $prefix="HDU";
    private $con;
    private $imgi;
    private $problemSet = [];
    private $noj_cid;
    protected $selectedJudger;

    public function __construct($all_data) {
        $this->oid=OJModel::oid('hdu');
        $this->vcid=$all_data['vcid'];
        $this->gid=$all_data['gid'];
        $this->noj_cid = isset($all_data['cid'])?$all_data['cid']:null;
        $judger=new JudgerModel();
        $judger_list=$judger->contestJudger($this->vcid);
        $this->selectedJudger=$judger_list[array_rand($judger_list)];
    }

    private function _loginAndGet($url)
    {
        $curl = new Curl();
        $response=$curl->grab_page([
            'site' => "http://acm.hdu.edu.cn/contests/contest_show.php?cid=".$this->vcid,
            'oj' => 'hdu', 
            'handle' => $this->selectedJudger["handle"]
        ]);
        if (strpos($response, 'Sign In')!==false) {
            $params=[
                'username' => $this->selectedJudger["handle"],
                'userpass' => $this->selectedJudger["password"],
                'login' => 'Sign In',
            ];
            $curl->login([
                'url' => 'http://acm.hdu.edu.cn/userloginex.php?cid='.$this->vcid, 
                'data' => http_build_query($params), 
                'oj' => 'hdu', 
                'handle' => $this->selectedJudger["handle"]
            ]);
        }
        return $curl->grab_page([
            'site'=>$url,
            'oj'=>'hdu',
            'handle'=>$this->selectedJudger["handle"],
        ]);
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
            file_put_contents(base_path("public/external/hdu/img/$fn"), $res);
            $ele->src='/external/hdu/img/'.$fn;
        }
        return $dom;
    }

    public function crawlProblem($con)
    {
        $this->_resetPro();
        $this->con = $con;
        $this->imgi = 1;
        $problemModel = new ProblemModel();
        $res = $this->_loginAndGet("http://acm.hdu.edu.cn/contests/contest_showproblem.php?pid={$con}&cid=".$this->vcid);
        $this->line("Crawling: {$con}\n");
        if (strpos($res,"No such problem") !== false) {
            return false;
        }
        if(strpos($res,"Invalid Parameter.") !== false) {
            return false;
        }
        $res = iconv("gb2312","utf-8//IGNORE",$res);
        $this->pro['pcode'] = $this->prefix.$this->vcid."-".$con;
        $this->pro['OJ'] = $this->oid;
        $this->pro['contest_id'] = null;
        $this->pro['index_id'] = $con;
        $this->pro['origin'] = "http://acm.hdu.edu.cn/contests/contest_showproblem.php?pid={$con}&cid=".$this->vcid;
        
        $this->pro['title'] = self::find('/<h1[\s\S]*?>([\s\S]*?)<\/h1>/',$res);
        $this->pro['time_limit'] = self::find('/Time Limit:.*\/(.*) MS/',$res);
        $this->pro['memory_limit'] = self::find('/Memory Limit:.*\/(.*) K/',$res);
        $this->pro['solved_count'] = self::find("/Accepted Submission(s): ([\d+]*?)/",$res);
        $this->pro['input_type'] = 'standard input';
        $this->pro['output_type'] = 'standard output';
        
        $this->pro['description'] = $this->cacheImage(HtmlDomParser::str_get_html(self::find("/Problem Description.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res), true, true, DEFAULT_TARGET_CHARSET, false));
        $this->pro['description'] = str_replace("$", "$$$", $this->pro['description']);
        $this->pro['input'] = $this->cacheImage(HtmlDomParser::str_get_html(self::find("/<div class=panel_title align=left>Input.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res), true, true, DEFAULT_TARGET_CHARSET, false));
        $this->pro['input'] = str_replace("$", "$$$", $this->pro['input']);
        $this->pro['output'] = $this->cacheImage(HtmlDomParser::str_get_html(self::find("/<div class=panel_title align=left>Output.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res), true, true, DEFAULT_TARGET_CHARSET, false));
        $this->pro['output'] = str_replace("$", "$$$", $this->pro['output']);
        $this->pro['sample'] = [];
        $this->pro['sample'][] = [
            'sample_input'=>self::find("/<pre><div.*>(.*)<\/div><\/pre>/sU",$res),
            'sample_output'=>self::find("/<div.*>Sample Output<\/div><div.*><pre><div.*>(.*)<\/div><\/pre><\/div>/sU",$res)
        ];
        $this->pro['note'] = self::find("/<i>Hint<\/i><\/div>(.*)<\/div><i style='font-size:1px'>/sU",$res);
        $this->pro['source'] = strip_tags(self::find("/<div class=panel_title align=left>Source<\/div> (.*)<div class=panel_bottom>/sU",$res));
            
        if($this->pro['source'] === "") {
            $this->pro['source'] = $this->pro['pcode'];
        }
        $problem=$problemModel->pid($this->pro['pcode']);

        if ($problem) {
            $problemModel->clearTags($problem);
            $new_pid=$this->updateProblem($this->oid);
            $this->pro['pid'] = $new_pid;
        } else {
            $new_pid=$this->insertProblem($this->oid);
            $this->pro['pid'] = $new_pid;
        }
        $this->pro['number'] = $con - 1000;
        $this->pro['points'] = 1;
        array_push($this->problemSet, $this->pro);
        return true;
    }

    public function crawlContest() {
        $contestModel = new ContestModel();
        $res = $this->_loginAndGet("http://acm.hdu.edu.cn/contests/contest_show.php?cid=".$this->vcid);
        $res = iconv("gb2312","utf-8//IGNORE",$res);
        // if (strpos($res,"Sign In Your Account") !== false) {
        //     throw new Exception("Permission denied.");
        //     return ;
        // }

        $contestInfo = [];
        $contestInfo['name'] = self::find('/<h1[\s\S]*?>([\s\S]*?)<\/h1>/',$res);
        $contestInfo['begin_time'] = self::find('/Start Time : ([\s\S]*?)&/',$res);
        $contestInfo['end_time'] = self::find('/End Time : ([\s\S]*?)"/',$res);
        $contestInfo["description"] = "";
        $contestInfo["vcid"] = $this->vcid;
        $contestInfo["assign_uid"] = 1;
        $contestInfo["practice"] = 1;
        $contestInfo["crawled"] = 0;

        $noj_cid = $contestModel->arrangeContest($this->gid, $contestInfo, $this->problemSet);
        $this->line("Successfully crawl the contest.\n");
    }

    public function scheduleCrawl() {
        if(!isset($this->noj_cid)) {
            throw new Exception("No such contest");
            return false;
        }
        $iteratorID = 1001;
        while(true) {
            $ret = $this->crawlProblem($iteratorID);
            if(!$ret) break;
            $iteratorID++;
            if($iteratorID > 1020) {
                $this->line("Something went wrong when crawling, stropping...\n");
                break;
            }
        }
        $contestModel = new ContestModel();
        $contestModel->contestUpdateProblem($this->noj_cid, $this->problemSet);
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
        $res = $this->_loginAndGet("http://acm.hdu.edu.cn/viewnotify.php?id={$id}&cid=".$this->vcid);
        $res = iconv("gb2312","utf-8//IGNORE",$res);
        if(strpos($res,"No such notification.") !== false) {
            return false;
        } else {
            $contestModel = new ContestModel();
            if(($contestModel->remoteAnnouncement($this->vcid."-".$id))!=null) {
                return true;
            }
            $title = self::find("/<strong>([\s\S]*?)<\/strong>/",$res);
            $contentRaw = self::find("/Time : ([\s\S]*?)Posted/",$res);
            $pos = strpos($contentRaw,"<\div>",-1);
            $content = trim(strip_tags(substr($contentRaw,$pos)));
            $contestModel->issueAnnouncement($this->noj_cid,$title,$content,1,$this->vcid."-".$id);
        }
    }

    public function crawlRank() 
    {
        if(!isset($this->noj_cid)) {
            throw new Exception("No such contest");
            return false;
        }
        $contestModel = new ContestModel();
        $res = $this->_loginAndGet("http://acm.hdu.edu.cn/contests/contest_ranklist.php?cid=".$this->vcid."&page=1");
        preg_match_all('/<a href=[\s\S]*?style="display: inline-block; padding: 5px 8px; font-weight: bold;">([\d+]*?)<\/a>/',$res,$matches);
        if(!isset($match[1])) {
            $totalPageNumber = sizeof($matches[1]) / 2;
        }
        $it = 1;
        $rank = [];
        $problemst = $contestModel->contestProblems($this->noj_cid,1);
        $problemNumber = sizeof($problemst);
        while($it <= $totalPageNumber) {
            $this->line("Crawling: Page{$it}\n");
            $ret = $this->_loginAndGet("http://acm.hdu.edu.cn/contests/contest_ranklist.php?cid=".$this->vcid."&page={$it}");
            $ret = iconv("gb2312","utf-8//IGNORE",$ret);
            $pattern = "/<td>([\s\S]*?)<\/td>";
            for($i = 1;$i <=3; $i++) {
                $pattern .= "[\s\s]*?<td>([\s\S]*?)<\/td>";
            }
            for($i = 1;$i < $problemNumber;$i++) {
                $pattern .= "[\s\S]*?<td[\s\S]*?>([\s\S]*?)<\/td>";
            }
            $pattern .= "[\s\S]*?<td[\s\S]*?>([\s\S]*?)<\/td>/";
            preg_match_all($pattern,$ret,$matches);
            $teamNumber = sizeof($matches[1]) - 1;
            for($i = 1; $i <= $teamNumber; $i++) {
                $team = [];
                $team['uid'] = null;
                $team['name'] = strip_tags($matches[2][$i]);
                $team['nick_name'] = null;
                $team['score'] = $matches[3][$i];
                $originTime = $matches[4][$i];
                $hour = intval(substr($originTime,0,3))*60;
                $minute = intval(substr($originTime,3,5));
                $team['penalty'] = $hour + $minute;
                $problems = [];
                for($j = 1; $j <= $problemNumber; $j++) {
                    $prob = [];
                    $prob['ncode'] = chr($j + 64);
                    $prob['pid'] = $problemst[$j-1]['pid'];
                    if($matches[$j+4][$i]!="&nbsp;") {
                        if($matches[$j+4][$i][0]!="(") {
                            $prob['color'] = "wemd-green-text";
                        }else {
                            $prob['color'] = "";
                        }
                        if(strpos($matches[$j+4][$i],"-")!==false) {
                            $startM = strpos($matches[$j+4][$i],"-");
                            $endM = strpos($matches[$j+4][$i],")");
                            $prob["wrong_doings"] = intval(substr($matches[$j+4][$i],$startM+1,$endM-$startM-1));
                        } else {
                            $prob["wrong_doings"] = 0;
                        }
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
            $it++;
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
        Cache::tags(['contest', 'rank'])->put($this->noj_cid, $rankFinal, 120);
        Cache::tags(['contest', 'rank'])->put("contestAdmin".$this->noj_cid, $rankFinal, 120);
        $this->line("Successfully refresh the rank.");
    }
}
