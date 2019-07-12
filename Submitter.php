<?php
namespace App\Babel\Extension\hdu;

use App\Babel\Submit\Curl;
use App\Models\CompilerModel;
use App\Models\JudgerModel;
use App\Models\OJModel;
use Illuminate\Support\Facades\Validator;
use Requests;

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

    public function submit()
    {
        $validator=Validator::make($this->post_data, [
            'pid' => 'required|integer',
            'coid' => 'required|integer',
            'iid' => 'required|integer',
            'solution' => 'required',
        ]);

        if ($validator->fails()) {
            $this->sub['verdict']="System Error";
            return;
        }

        $this->_login();
        $this->_submit();
    }
}
