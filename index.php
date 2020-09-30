<?php
//获得参数 signature nonce token timestamp echostr
$nonce = $_GET['nonce'];
$token = 'YourToken';
$timestamp = $_GET['timestamp'];
$echostr = $_GET['echostr'];
$signature = $_GET['signature'];
//形成数组，然后按字典序排序
$array = array();
$array = array($nonce, $timestamp, $token);
sort($array);
//拼接成字符串,sha1加密 ，然后与signature进行校验
$str = sha1(implode($array));
if ($str == $signature && $echostr) {
    //第一次接入weixin api接口的时候
    echo $echostr;
    exit;
} else {
    //1.获取到微信推送过来post数据（xml格式）
//    $postArr = $GLOBALS['HTTP_RAW_POST_DATA'];
    $postArr = file_get_contents('php://input');
    //2.处理消息类型，并设置回复类型和内容
    lLog("postArr:  " . $postArr);
    $postObj = simplexml_load_string($postArr);
    //事件推送
    if (strtolower($postObj->MsgType) == 'event') {
        //如果是关注 subscribe 事件
        if (strtolower($postObj->Event == 'subscribe')) {
            //回复用户消息(纯文本格式)
            $toUser = $postObj->FromUserName;
            $fromUser = $postObj->ToUserName;
            $content = "您好，感谢关注火车经停时间，\n很高兴为您服务！\n输入“车次”(如“G1”)，可查询该车次的列车时刻表。";
            echo TextTemplate($toUser, $fromUser, $content);
            exit;
        }
    }
    //文本推送
    if (strtolower($postObj->MsgType) == 'text') {
        $trainNo = $postObj->Content;
        lLog("trainNo:    " . $trainNo);
        if (empty($trainNo)) {
            $content = "请直接输入车次信息 例如： G1";
        } else {
            $content = getTrainStopTime($trainNo);
            lLog("train info:   " . $content);
            if ($content == false) {
                $content = "抱歉没有查询到 {$trainNo} 车次信息，请输入正确的车次，如：G1";
            }
        }
        echo TextTemplate($postObj->FromUserName, $postObj->ToUserName, $content);
        exit;
    }
}

function TextTemplate($toUser, $fromUser, $content)
{
    $time = time();
    $msgType = 'text';
    $template = "<xml>
                                <ToUserName><![CDATA[%s]]></ToUserName>
                                <FromUserName><![CDATA[%s]]></FromUserName>
                                <CreateTime>%s</CreateTime>
                                <MsgType><![CDATA[%s]]></MsgType>
                                <Content><![CDATA[%s]]></Content>
                         </xml>";
    return sprintf($template, $toUser, $fromUser, $time, $msgType, $content);
}

function getTrainStopTime($trainNo)
{
    $url = "http://m.ctrip.com/restapi/soa2/14666/json/SearchTrainDelay";
//    $post_filed = ["trainNo" => strval($trainNo)];
    $body = "trainNo=" . strval($trainNo);
    $data = postCurl($url, $body, ["Content-Type:application/x-www-form-urlencoded; charset=utf-8"]);
    $dataArr = json_decode($data, true);
    lLog("dataArr:   " . $dataArr);
    // 手机微信端一行最多15个中文字符，linux默认utf8下1中文字符占3个字节
    $response = "车站         到站      离站      停留\n";
    if (isset($dataArr["trainDelayInfos"])) {
        for ($i=0; $i < count($dataArr["trainDelayInfos"]); $i++) { 
            $item = $dataArr["trainDelayInfos"][$i];
            if (isset($item["stationName"]) && isset($item["stopMinutes"])) {
                $stationName = $item["stationName"];
                $arrivalTime = $item["arrivalTime"];
                $startTime = $item["startTime"];
                $stopMinutes = $item["stopMinutes"];

                if ($stopMinutes == "----") {
                    if ($i == 0) { // 始发
                        $arrivalTime = "始发";
                    } else { // 终点
                        $startTime = "终点";
                    }
                    $stopMinutes = "     ";
                }

                if (strlen($stationName) < 12) {
                    $stationName = str_pad($stationName, 12, " ", STR_PAD_RIGHT);
                }
                if (strpos($stopMinutes, " ") === false) {
                    $stopMinutes = str_pad($stopMinutes."分", 8, " ", STR_PAD_BOTH);
                }
                if (strlen($arrivalTime) < 10) {
                    $arrivalTime = str_pad($arrivalTime, 10, " ", STR_PAD_BOTH);
                }
                if (strlen($startTime) < 10) {
                    $startTime = str_pad($startTime, 10, " ", STR_PAD_BOTH);
                }
                $response .= $stationName .  $arrivalTime . $startTime .  $stopMinutes . "\n";
            }
        }
        return $response;
    }
    return false;
}


// 写日志
function lLog($content)
{
    $dir = "log";
    if (!is_dir($dir)) {
        mkdir($dir, 0777);
    }
    $file = $dir . "/" . date("Y-m-d") . ".log";
    $content = date("Y-m-d H:i:s") . "   " . $content . "\n";
    if (!file_put_contents($file, $content, FILE_APPEND)) {
        error_log("日志写入失败");
    }
}

//post 请求
function curl_post($url, $data = array())
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;

}


function curl_post_header($url, $post_fields = array(), $header = array())
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);//用PHP取回的URL地址（值将被作为字符串）
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//使用curl_setopt获取页面内容或提交数据，有时候希望返回的内容作为变量存储，而不是直接输出，这时候希望返回的内容作为变量
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);//10秒超时限制
    curl_setopt($ch, CURLOPT_HEADER, 1);//将文件头输出直接可见。
    if (count($header) > 0) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }
    curl_setopt($ch, CURLOPT_POST, 1);//设置这个选项为一个零非值，这个post是普通的application/x-www-from-urlencoded类型，多数被HTTP表调用。
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);//post操作的所有数据的字符串。
    $data = curl_exec($ch);//抓取URL并把他传递给浏览器
    lLog("curl data:  " . $data);
    curl_close($ch);//释放资源
    $res = explode("\r\n\r\n", $data);//explode把他打散成为数组

    return $res; //然后在这里返回数组。
}


function postCurl($url, $body, $header, $type = "POST")
{
    //1.创建一个curl资源
    $ch = curl_init();
    //2.设置URL和相应的选项
    curl_setopt($ch, CURLOPT_URL, $url);//设置url
    //1)设置请求头
    //array_push($header, 'Accept:application/json');
    //array_push($header,'Content-Type:application/json');
    //array_push($header, 'http:multipart/form-data');
    //设置为false,只会获得响应的正文(true的话会连响应头一并获取到)
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 设置超时限制防止死循环
    //设置发起连接前的等待时间，如果设置为0，则无限等待。
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    //将curl_exec()获取的信息以文件流的形式返回，而不是直接输出。
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //2)设备请求体
    if (count($body) > 0) {
        //$b=json_encode($body,true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);//全部数据使用HTTP协议中的"POST"操作来发送。
    }
    if (count($header) > 0) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);// 对认证证书来源的检查
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);// 从证书中检查SSL加密算

    switch ($type) {
        case "GET":
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            break;
        case "POST":
            curl_setopt($ch, CURLOPT_POST, true);
            break;
        case "PUT"://使用一个自定义的请求信息来代替"GET"或"HEAD"作为HTTP请									                     求。这对于执行"DELETE" 或者其他更隐蔽的HTT
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            break;
        case "DELETE":
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
    }
    curl_setopt($ch, CURLOPT_USERAGENT, 'SSTS Browser/1.0');
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.0; Trident/4.0)'); // 模拟用户使用的浏览器
    $res = curl_exec($ch);
    lLog("curl res: " . strval($res));
    curl_close($ch);
    return $res;

}