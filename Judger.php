<?php
namespace App\Babel\Extension\hdu;

use App\Babel\Submit\Curl;
use App\Models\SubmissionModel;
use App\Models\ProblemModel;
use App\Models\JudgerModel;
use Requests;
use Exception;

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

    public function judge($row)
    {
        $sub = [];
        if(!isset($row['vcid'])) {
            $response = Requests::get("http://acm.hdu.edu.cn/status.php?first=".$row['remote_id']);
        } else {
            $handle = $this->model["judgerModel"]->detail($row['jid'])['handle'];
            $iid = $this->model['problemModel']->basic($row['pid'])['index_id'];
            $response = Requests::get("http://acm.hdu.edu.cn/contests/contest_status.php?cid=".$row['vcid']."&user=".$handle."&pid=".$iid);
        }
        preg_match ('/<\/td><td>[\\s\\S]*?<\/td><td>[\\s\\S]*?<\/td><td>([\\s\\S]*?)<\/td><td>[\\s\\S]*?<\/td><td>(\\d*?)MS<\/td><td>(\\d*?)K<\/td>/', $response->body, $match);
        if(strpos(trim(strip_tags($match[1])), 'Runtime Error')!==false)  $sub['verdict'] = 'Runtime Error';
        else $sub['verdict'] = $hdu_v[trim(strip_tags($match[1]))];
        preg_match ("/<td>(\\d*?)MS<\/td><td>(\\d*?)K<\/td>/", $response->body, $matches);
        $sub['remote_id'] = $row['remote_id'];
        $sub['time'] = intval($matches[1]);
        $sub['memory'] = intval($matches[2]);

        if($sub['verdict'] == 'Compile Error') {
            $ret = Requests::get("http://acm.hdu.edu.cn/viewerror.php?cid=".$row['vcid']."&rid=".$row['remote_id']);
            preg_match ("/<pre>([\\s\\S]*?)<\/pre>/", $ret->body, $match);
            $sub['compile_info'] = trim(strip_tags($match[0]));
        }

        $this->model["submissionModel"]->updateSubmission($row['sid'], $sub);
    }
}
