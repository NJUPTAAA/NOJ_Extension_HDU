<?php
namespace App\Babel\Extension\hdu;

use App\Babel\Submit\Curl;
use App\Models\CompilerModel;
use App\Models\JudgerModel;
use App\Models\OJModel;
use Illuminate\Support\Facades\Validator;
use Requests;
use Log;

class Submitter extends Curl
{
    protected $sub;
    public $post_data=[];
    public $oid;
    protected $selectedJudger;

    public function __construct(& $sub, $all_data)
    {
        $this->sub=& $sub;
        $this->post_data=$all_data;
        $judger=new JudgerModel();
        $this->oid=OJModel::oid('hdu');
        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }
        $judger_list=$judger->list($this->oid);
        $this->selectedJudger=$judger_list[array_rand($judger_list)];
    }

    private function _login()
    {
        $response=$this->grab_page([
            'site' => 'http://acm.hdu.edu.cn',
            'oj' => 'hdu', 
            'handle' => $this->selectedJudger["handle"]
        ]);
        if (strpos($response, 'Sign In')!==false) {
            $params=[
                'username' => $this->selectedJudger["handle"],
                'userpass' => $this->selectedJudger["password"],
                'login' => 'Sign In',
            ];
            $this->login([
                'url' => 'http://acm.hdu.edu.cn/userloginex.php?action=login', 
                'data' => http_build_query($params), 
                'oj' => 'hdu', 
                'handle' => $this->selectedJudger["handle"]
            ]);
        }
    }

    private function _submit()
    {
        $params=[
            'problemid' => $this->post_data['iid'],
            'language' => $this->post_data['lang'],
            'usercode' => $this->post_data["solution"],
            'submit' => 'Submit',
        ];

        $response=$this->post_data([
            'site' => "http://acm.hdu.edu.cn/submit.php?action=submit", 
            'data' => http_build_query($params), 
            'oj' => "hdu", 
            "ret" => true,
            "follow" => false,
            "returnHeader" => true,
            "postJson" => false,
            "extraHeaders" => [],
            "handle" => $this->selectedJudger["handle"]
        ]);
        $this->sub['jid'] = $this->selectedJudger['jid'];
        $res = Requests::get('http://acm.hdu.edu.cn/status.php?user='.$this->selectedJudger['handle'].'&pid='.$this->post_data['iid']);
        if (!preg_match("/<td height=22px>([\s\S]*?)<\/td>/", $res->body, $match)) {
                $this->sub['verdict']='Submission Error';
        } else {
                $this->sub['remote_id']=$match[1];
        }
    }
    private function _loginAndGet($url)
    {
        $curl = new Curl();
        $response=$curl->grab_page([
            'site' => 'http://acm.hdu.edu.cn/contests/contest_show.php?cid='.$this->post_data['vcid'],
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
                'url' => 'http://acm.hdu.edu.cn/userloginex.php?cid='.$this->post_data['vcid'], 
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
    private function grab($all_data) {
        $ch = curl_init();

        // Log::alert($all_data['site']);
        curl_setopt($ch, CURLOPT_URL, $all_data['site']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');


        $headers = array();
        // $headers[] = 'Cookie: PHPSESSID=1uv8lhltg2ceas7d8qtgon0cc2';
        // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIEFILE, babel_path("Cookies/hdu_team0670.cookie"));
        curl_setopt($ch, CURLOPT_COOKIEJAR, babel_path("Cookies/hdu_team0670.cookie"));

        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
    private function __loginAndGet($url)
    {
        $curl = new Curl();
        $response=$curl->grab_page([
            'site' => 'http://acm.hdu.edu.cn/contests/contest_show.php?cid='.$this->post_data['vcid'],
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
                'url' => 'http://acm.hdu.edu.cn/userloginex.php?cid='.$this->post_data['vcid'], 
                'data' => http_build_query($params), 
                'oj' => 'hdu', 
                'handle' => $this->selectedJudger["handle"]
            ]);
        }
        return $this->grab([
            'site'=>$url,
            'oj'=>'hdu',
            'handle'=>$this->selectedJudger["handle"],
        ]);
    }
    private function _contestLogin()
    {
        $curl = new Curl();
        $response=$curl->grab_page([
            'site' => 'http://acm.hdu.edu.cn/contests/contest_show.php?cid='.$this->post_data['vcid'],
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
                'url' => 'http://acm.hdu.edu.cn/userloginex.php?cid='.$this->post_data['vcid'], 
                'data' => http_build_query($params), 
                'oj' => 'hdu', 
                'handle' => $this->selectedJudger["handle"]
            ]);
        }
    }

    private function contestSubmit() {
        $this->_contestLogin();
        $params=[
            'problemid' => $this->post_data['iid'],
            'language' => $this->post_data['lang'],
            'usercode' => base64_encode($this->post_data["solution"]),
            // 'submit' => 'Submit',
        ];

        $pid = $this->post_data['iid'];
        $vcid = $this->post_data['vcid'];
        $response=$this->post_data([
            // 'site' => "http://acm.hdu.edu.cn/contests/contest_submit.php?cid={$vcid}&pid={$pid}", 
            'site' => "http://acm.hdu.edu.cn/contests/contest_submit.php?action=submit&cid={$vcid}", 
            'data' => http_build_query($params), 
            'oj' => "hdu", 
            "ret" => true,
            "follow" => false,
            "returnHeader" => true,
            "handle" => $this->selectedJudger["handle"]
        ]);
        $this->sub['jid'] = $this->selectedJudger['jid'];
        $this->sub['vcid'] = $vcid;
        $res = $this->__loginAndGet("http://acm.hdu.edu.cn/contests/contest_status.php?cid={$vcid}&user=".$this->selectedJudger['handle'].'&pid='.$this->post_data['iid']);
        // Log::debug($res);
        if (!preg_match('/<td height=22>([\s\S]*?)<\/td>/', $res, $match)) {
                $this->sub['verdict']='Submission Error';
        } else {
                $this->sub['remote_id']=$match[1];
        }
    }

    public function submit()
    {
        $validator=Validator::make($this->post_data, [
            'pid' => 'required|integer',
            'coid' => 'required|integer',
            'iid' => 'required|integer',
            'solution' => 'required|min:51|max:65535',
        ]);

        if ($validator->fails()) {
            $this->sub['verdict']="System Error";
            return;
        }

        Log::debug($this->post_data);
        if(!isset($this->post_data['vcid'])) {
            $this->_login();
            $this->_submit();
        } else {
            $this->contestSubmit();
        }
    }
}
