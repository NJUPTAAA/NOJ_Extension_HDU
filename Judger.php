<?php
namespace App\Babel\Extension\hdu;

use App\Babel\Submit\Curl;
use App\Models\SubmissionModel;
use App\Models\ProblemModel;
use App\Models\JudgerModel;
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

    private function _login($jid,$vcid)
    {
        $judger = new JudgerModel();
        $list = $judger->detail($jid);
        $response=$this->grab_page([
            'site' =>  'http://acm.hdu.edu.cn/contests/contest_show.php?cid='.$vcid,
            'oj' => 'hdu', 
            'handle' => $list["handle"]
        ]);
        if (strpos($response, 'Sign In')!==false) {
            $params=[
                'username' => $list["handle"],
                'userpass' => $list["password"],
                'login' => 'Sign In',
            ];
            $this->login([
                'url' => 'http://acm.hdu.edu.cn/userloginex.php?cid='.$vcid, 
                'data' => http_build_query($params), 
                'oj' => 'hdu', 
                'handle' => $list["handle"]
            ]);
        }
    }

    private function grab($all_data) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $all_data['site']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');


        $headers = array();
        curl_setopt($ch, CURLOPT_COOKIEFILE, babel_path("Cookies/hdu_team0670.cookie"));
        curl_setopt($ch, CURLOPT_COOKIEJAR, babel_path("Cookies/hdu_team0670.cookie"));

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
            $this->_login($row['jid'],$row['vcid']);
            $handle = $this->model["judgerModel"]->detail($row['jid'])['handle'];
            $iid = $this->model['problemModel']->basic($row['pid'])['index_id'];
            $pass = $this->model["judgerModel"]->detail($row['jid'])['password'];
            $response = $this->_loginAndGet("http://acm.hdu.edu.cn/contests/contest_status.php?cid=".$row['vcid']."&user=".$handle."&pid=".$iid,$handle,$pass,$row['vcid']);
        }
        if(isset($row['vcid'])) {
            preg_match ('/<\/td><td>[\\s\\S]*?<\/td><td>([\\s\\S]*?)<\/td><td>[\\s\\S]*?<\/td><td>[\\s\\S]*?<\/td><td>(\\d*?)MS<\/td><td>(\\d*?)K<\/td>/', $response, $match);
        }else {
            preg_match ('/<\/td><td>[\\s\\S]*?<\/td><td>[\\s\\S]*?<\/td><td>([\\s\\S]*?)<\/td><td>[\\s\\S]*?<\/td><td>(\\d*?)MS<\/td><td>(\\d*?)K<\/td>/', $response, $match);
        }
        if(strpos(trim(strip_tags($match[1])), 'Runtime Error')!==false)  $sub['verdict'] = 'Runtime Error';
        else $sub['verdict'] = $this->verdict[trim(strip_tags($match[1]))];
        preg_match ("/<td>(\\d*?)MS<\/td><td>(\\d*?)K<\/td>/", $response, $matches);
        $sub['remote_id'] = $row['remote_id'];
        $sub['time'] = intval($matches[1]);
        $sub['memory'] = intval($matches[2]);

        if($sub['verdict'] == 'Compile Error') {
            $ret = $this->_loginAndGet("http://acm.hdu.edu.cn/viewerror.php?cid=".$row['vcid']."&rid=".$row['remote_id'], $handle, $pass, $row["vcid"]);
            preg_match ("/<pre>([\\s\\S]*?)<\/pre>/", $ret, $match);
            $sub['compile_info'] = trim(strip_tags($match[0]));
        }

        $this->model["submissionModel"]->updateSubmission($row['sid'], $sub);
    }
}
