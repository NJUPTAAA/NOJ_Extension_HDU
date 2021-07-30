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
    private $action;
    private $cached;

    /**
     * Initial
     *
     * @return Response
     */
    public function start($conf)
    {
        $this->action=isset($conf["action"])?$conf["action"]:'crawl_problem';
        $con=isset($conf["con"])?$conf["con"]:'all';
        $this->cached=isset($conf["cached"])?$conf["cached"]:false;
        $this->oid=OJModel::oid('hdu');

        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }
        if ($this->action=='judge_level') {
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
        if(!$dom) return $dom;
        foreach ($dom->find('img') as $ele) {
            $src=str_replace('../../..', '', $ele->src);
            if (strpos($src, '://')!==false) {
                $url=$src;
            } elseif ($src[0]=='/') {
                $url='https://acm.hdu.edu.cn'.$src;
            } else {
                $url='https://acm.hdu.edu.cn/'.$src;
            }
            $res=Requests::get($url, ['Referer' => 'https://acm.hdu.edu.cn']);
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
        if($con=='all'){
            $HDUVolume = HtmlDomParser::str_get_html(Requests::get("https://acm.hdu.edu.cn/listproblem.php", ['Referer' => 'https://acm.hdu.edu.cn'])->body, true, true, DEFAULT_TARGET_CHARSET, false);
            $_lastVolume = intval($HDUVolume->find('a[style="margin:5px"]', -1)->plaintext);
            $lastVolume = isset($_lastVolume)?$_lastVolume:56;
            $HDUVolumePage = HtmlDomParser::str_get_html(Requests::get("https://acm.hdu.edu.cn/listproblem.php?vol=$lastVolume", ['Referer' => 'https://acm.hdu.edu.cn'])->body, true, true, DEFAULT_TARGET_CHARSET, false);
            $_lastProbID = intval($HDUVolumePage->find('tr[height="22"]', -1)->find("td", 0)->plaintext);
            $lastProbID = $_lastProbID != 0?$_lastProbID:6633;
            foreach (range(1000, $lastProbID) as $probID) {
                $this->_crawl($probID, 5);
            }
        }else{
            $this->_crawl($con, 5);
        }
    }

    protected function _crawl($con, $retry=1)
    {
        $attempts=1;
        while($attempts <= $retry){
            try{
                $this->crawlProblem($con);
            }catch(Exception $e){
                $attempts++;
                continue;
            }
            break;
        }
    }

    public function crawlProblem($con)
    {
        $this->_resetPro();
        $this->con = $con;
        $this->imgi = 1;
        $problemModel = new ProblemModel();
        if(!empty($problemModel->basic($problemModel->pid($this->prefix.$con))) && $this->action=="update_problem"){
            return;
        }
        if($this->action=="crawl_problem") $this->line("<fg=yellow>Crawling:   </>{$this->prefix}{$con}");
        elseif($this->action=="update_problem") $this->line("<fg=yellow>Updating:   </>{$this->prefix}{$con}");
        else return;
        $res = Requests::get("https://acm.hdu.edu.cn/showproblem.php?pid={$con}");
        if (strpos($res->body,"No such problem") !== false) {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Can not find problem.</>\n");
            throw new Exception("Can not find problem");
        }
        if(strpos($res->body,"Invalid Parameter.") !== false) {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Can not find problem.</>\n");
            throw new Exception("Can not find problem");
        }
        $res->body = iconv("gb2312","utf-8//IGNORE",$res->body);
        $this->pro['pcode'] = $this->prefix.$con;
        $this->pro['OJ'] = $this->oid;
        $this->pro['contest_id'] = null;
        $this->pro['index_id'] = $con;
        $this->pro['origin'] = "https://acm.hdu.edu.cn/showproblem.php?pid={$con}";
        
        $this->pro['title'] = self::find("/<h1 style='color:#1A5CC8'>([\s\S]*?)<\/h1>/",$res->body);
        if($this->pro['title'] == "") {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Can not find problem.</>\n");
            throw new Exception("Can not find problem");
        }
        $this->pro['time_limit'] = self::find('/Time Limit:.*\/(.*) MS/',$res->body);
        $this->pro['memory_limit'] = self::find('/Memory Limit:.*\/(.*) K/',$res->body);
        $this->pro['solved_count'] = self::find("/Accepted Submission(s): ([\d+]*?)/",$res->body);
        $this->pro['input_type'] = 'standard input';
        $this->pro['output_type'] = 'standard output';
        
        $this->pro['description'] = $this->cacheImage(HtmlDomParser::str_get_html(self::find("/Problem Description.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res->body), true, true, DEFAULT_TARGET_CHARSET, false));
        $this->pro['description'] = str_replace("$", "$$$", $this->pro['description']);
        $this->pro['input'] = $this->cacheImage(HtmlDomParser::str_get_html(self::find("/<div class=panel_title align=left>Input.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res->body), true, true, DEFAULT_TARGET_CHARSET, false));
        $this->pro['input'] = str_replace("$", "$$$", $this->pro['input']);
        $this->pro['output'] = $this->cacheImage(HtmlDomParser::str_get_html(self::find("/<div class=panel_title align=left>Output.*<div class=panel_content>(.*)<\/div><div class=panel_bottom>/sU",$res->body), true, true, DEFAULT_TARGET_CHARSET, false));
        $this->pro['output'] = str_replace("$", "$$$", $this->pro['output']);
        $this->pro['sample'] = [];
        $this->pro['sample'][] = [
            'sample_input'=>self::find("/<pre><div.*>(.*)<\/div><\/pre>/sU",$res->body),
            'sample_output'=>self::find("/<div.*>Sample Output<\/div><div.*><pre><div.*>(.*)<\/div><\/pre><\/div>/sU",$res->body)
        ];
        $this->pro['sample'][0]['sample_output']=explode("<div style=",$this->pro['sample'][0]['sample_output'])[0];
        $this->pro['note'] = self::find("/<i>Hint<\/i><\/div>(.*)<\/div><i style='font-size:1px'>/sU",$res->body);
        if (!is_null($this->pro['note'])) {
            $this->pro['note'] = "<pre style='background: transparent; border: transparent; margin: 0; padding: 0; font-size: 1rem; white-space: pre-wrap; word-wrap: break-word;'>".$this->pro['note']."</pre>";
        }
        $this->pro['source'] = strip_tags(self::find("/<div class=panel_title align=left>Source<\/div> (.*)<div class=panel_bottom>/sU",$res->body));
            
        if($this->pro['source'] === "") {
            $this->pro['source'] = $this->pro['pcode'];
        }
        $problem=$problemModel->pid($this->pro['pcode']);

        if ($problem) {
            $problemModel->clearTags($problem);
            $new_pid=$this->updateProblem($this->oid);
        } else {
            $new_pid=$this->insertProblem($this->oid);
        }

        if($this->action=="crawl_problem") $this->line("<fg=green>Crawled:    </>{$this->prefix}{$con}");
        elseif($this->action=="update_problem") $this->line("<fg=green>Updated:    </>{$this->prefix}{$con}");
    }
}