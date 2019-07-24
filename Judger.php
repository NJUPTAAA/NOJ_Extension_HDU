<?php
namespace App\Babel\Extension\hdu;

use App\Babel\Submit\Curl;
use App\Models\SubmissionModel;
use App\Models\ProblemModel;
use App\Models\JudgerModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;
use Log;

class Judger extends Curl
{

    public $verdict=[
        'Accepted'=>"Accepted",
        "Presentation Error"=>"Presentation Error",
        'Time Limit Exceeded'=>"Time Limit Exceed",
        "Memory Limit Exceeded"=>"Memory Limit Exceed",
        'Wrong Answer'=>"Wrong Answer",
        'Runtime Error'=>"Runtime Error",
        'Output Limit Exceeded'=>"Output Limit Exceeded",
        'Compilation Error'=>"Compile Error",
    ];
    private $model=[];

    public function __construct()
    {
        $this->model["submissionModel"]=new SubmissionModel();
        $this->model["judgerModel"]=new JudgerModel();
        $this->model["problemModel"]=new ProblemModel();
    }

    private function _login($handle, $pass, $vcid)
    {
        $response=$this->grab_page([
            'site' =>  'http://acm.hdu.edu.cn/contests/contest_show.php?cid='.$vcid,
            'oj' => 'hdu', 
            'handle' => $handle
        ]);
        if (strpos($response, 'Sign In')!==false) {
            $params=[
                'username' => $handle,
                'userpass' => $pass,
                'login' => 'Sign In',
            ];
            $this->login([
                'url' => 'http://acm.hdu.edu.cn/userloginex.php?cid='.$vcid, 
                'data' => http_build_query($params), 
                'oj' => 'hdu', 
                'handle' => $handle
            ]);
        }
    }

    private function grab($all_data) {
        $oj = $all_data['oj'];
        $handle = $all_data['handle'];
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $all_data['site']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');


        $headers = array();
        curl_setopt($ch, CURLOPT_COOKIEFILE, babel_path("Cookies/{$oj}_{$handle}.cookie"));
        curl_setopt($ch, CURLOPT_COOKIEJAR, babel_path("Cookies/{$oj}_{$handle}.cookie"));

        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function _loginAndGet($url,$handle,$pass,$vcid)
    {
        $curl = new Curl();
        $response=$curl->grab_page([
            'site' => 'http://acm.hdu.edu.cn/contests/contest_show.php?cid='.$vcid,
            'oj' => 'hdu', 
            'handle' => $handle
        ]);
        if (strpos($response, 'Sign In')!==false) {
            $params=[
                'username' => $handle,
                'userpass' => $pass,
                'login' => 'Sign In',
            ];
            $curl->login([
                'url' => 'http://acm.hdu.edu.cn/userloginex.php?cid='.$vcid, 
                'data' => http_build_query($params), 
                'oj' => 'hdu', 
                'handle' => $handle
            ]);
        }
        return $this->grab([
            'site'=>$url,
            'oj'=>'hdu',
            'handle'=>$handle,
        ]);
    }
    
    public function judge($row)
    {
        $sub = [];
        if(!isset($row['vcid'])) {
            $response = Requests::get("http://acm.hdu.edu.cn/status.php?first=".$row['remote_id'])->body;
        } else {
            $judger = $this->model["judgerModel"]->contestJudgerDetail($row['jid']);
            $iid = $this->model['problemModel']->basic($row['pid'])['index_id'];
            $handle = $judger['handle'];
            $pass = $judger['password'];
            $this->_login($handle, $pass, $row['vcid']);
            $response = $this->_loginAndGet("http://acm.hdu.edu.cn/contests/contest_status.php?cid=".$row['vcid']."&user=".$handle."&pid=".$iid, $handle, $pass, $row['vcid']);
            Log::debug($response);
        }
        if(isset($row['vcid'])) {
            $hduRes = HTMLDomParser::str_get_html($response, true, true, DEFAULT_TARGET_CHARSET, false);
            foreach($hduRes->find('tr') as $ele) {
                $elements=$ele->children();
                if($elements[0]->plaintext==$row['remote_id']) {
                    $sub['verdict'] = $this->verdict[trim($elements[2]->plaintext)];
                    $sub['time'] = intval(substr($elements[4]->plaintext,0,strpos($elements[4]->plaintext,"M")));
                    $sub['memory'] = intval(substr($elements[5]->plaintext,0,strpos($elements[5]->plaintext,"K")));
                    $sub['remote_id'] = $row['remote_id'];
                    if($sub['verdict'] == 'Compile Error') {
                        $ret = Requests::get("http://acm.hdu.edu.cn/viewerror.php?cid=".$row['vcid']."&rid=".$row['remote_id'])->body;
                        preg_match ("/<pre>([\\s\\S]*?)<\/pre>/", $ret, $match);
                        $sub['compile_info'] = trim(strip_tags($match[0]));
                    }
                    $this->model["submissionModel"]->updateSubmission($row['sid'], $sub);
                    return ;
                }
            }
        }else {
            preg_match ('/<\/td><td>[\\s\\S]*?<\/td><td>[\\s\\S]*?<\/td><td>([\\s\\S]*?)<\/td><td>[\\s\\S]*?<\/td><td>(\\d*?)MS<\/td><td>(\\d*?)K<\/td>/', $response, $match);
            if(strpos(trim(strip_tags($match[1])), 'Runtime Error')!==false)  $sub['verdict'] = 'Runtime Error';
            else $sub['verdict'] = $this->verdict[trim(strip_tags($match[1]))];
            preg_match ("/<td>(\\d*?)MS<\/td><td>(\\d*?)K<\/td>/", $response, $matches);
            $sub['remote_id'] = $row['remote_id'];
            $sub['time'] = intval($matches[1]);
            $sub['memory'] = intval($matches[2]);

            if($sub['verdict'] == 'Compile Error') {
                if(isset($row['vcid'])) {
                    $ret = Requests::get("http://acm.hdu.edu.cn/viewerror.php?cid=".$row['vcid']."&rid=".$row['remote_id'])->body;
                }else {
                    $ret = Requests::get("http://acm.hdu.edu.cn/viewerror.php?rid=".$row['remote_id'])->body;
                }
                preg_match ("/<pre>([\\s\\S]*?)<\/pre>/", $ret, $match);
                $sub['compile_info'] = trim(strip_tags($match[0]));
            }

            $this->model["submissionModel"]->updateSubmission($row['sid'], $sub);
        }
    }
}
