<?php

define('_ENVATO_DEBUG_MODE',true);
define('_ENVATO_TMP_DIR',dirname(__FILE__).'/envato-cache/');
define('_ENVATO_SECRET',"rhrh65zre6heasdkfhasdf8672323423nlkj2h42kjgasf"); // some unique code

class envato_scraper{


    private $waiting_on_recaptcha = false;
    private $logged_in = false;
    public $username = false;
    // list of all supported marketplaces.
    private $marketplaces = array(
            "http://themeforest.net",
            "http://codecanyon.net",
            "http://activeden.net",
            "http://audiojungle.net",
            "http://videohive.net",
            "http://graphicriver.net",
            "http://3docean.net",
            "http://photodune.net",
        );
    private $authed_marketplaces=array();// which ones we have /sign_in?auto=true&to=X to
    private $authenticity_tokens=array();
    private $main_marketplace = 'http://codecanyon.net';

    public function __construct($main_marketplace='http://codecanyon.net'){
        if(in_array($main_marketplace,$this->marketplaces)){
            $this->main_marketplace = $main_marketplace;
        }
	    if(!is_dir(_ENVATO_TMP_DIR)){
		    mkdir(_ENVATO_TMP_DIR);
	    }
        if(!is_dir(_ENVATO_TMP_DIR) || !is_writable(_ENVATO_TMP_DIR)){
            echo 'please make sure the temp directory '._ENVATO_TMP_DIR.' is writable by PHP scripts.';
        }
        
    }

    /**
     * This pulls back list of all user items across all marketplaces (or specified marketplace)
     *
     * @param $user
     * @param array $from_marketplaces
     * @return array of items
     */
    public function get_users_items($user,$from_marketplaces=array()){
        $files = array();
        if(!is_array($from_marketplaces))$from_marketplaces=array($from_marketplaces);
        foreach($from_marketplaces as $marketplace){
                  //http://marketplace.envato.com/api/v2/new-files-from-user:collis,themeforest.json
            $url = "http://marketplace.envato.com/api/v2/new-files-from-user:$user,$marketplace.json";
            if(_ENVATO_DEBUG_MODE){
                echo " Grabbing API url: $url <bR>\n";
            }
            $data = $this->get_url($url,array(),false);
            if(!empty($data)) {
                $json_data = json_decode($data, true);
                if(_ENVATO_DEBUG_MODE){
                    echo "data: ";print_r($json_data);
                }
                $files = array_merge($files,$json_data['new-files-from-user']);
            }
        }
        return $files;
    }

    public function authenticate_marketplace($url){
        if(!in_array($url,$this->marketplaces))return false;
        $marketplace_tag = str_replace('.net','',str_replace('http://','',$url));
        if(isset($this->authed_marketplaces[$marketplace_tag])){
            $this->logged_in = true;
        }else{
            $auth_check = $this->get_url('https://account.envato.com/sign_in?auto=true&to='.$marketplace_tag,array(),true); // todo - force this one?
            if(!$auth_check){
	            echo "failed to auth marketplace, try again.";
	            return false;
            }

            preg_match('#name="authenticity_token" type="hidden" value="([^"]+)"#',$auth_check ,$matches);
            $authenticity_token = $matches[1];

            preg_match('#name="token" type="hidden" value="([^"]+)"#',$auth_check ,$matches);
            $token = $matches[1];


	        if(_ENVATO_DEBUG_MODE){
		        echo "Authenticating $url to: ".'https://account.envato.com/sign_in?auto=true&to='.$marketplace_tag."<br>";
		        echo "Got auth: $authenticity_token and $token <br>";
	        }

            $post = array(
                'utf8' => '&#x2713;',
                'authenticity_token' => $authenticity_token,
                'token' => $token
            );
            $auth_check = $this->get_url('http://'.$marketplace_tag.'.net/sso/verify_token', $post, true);


	        if(_ENVATO_DEBUG_MODE){
		        //echo "Auth check result: $auth_check <br>";
	        }

            if(preg_match('#/sign_out["\?]#',$auth_check)){
                $this->authed_marketplaces[$marketplace_tag]=true;
                preg_match('#<meta content="([^"]+)" name="csrf-token" />#', $auth_check, $hits);
                $this->authenticity_tokens[$marketplace_tag]=$hits[1];
                return true;
            }
        }
        return false;
    }

    /**
     * do_login! The magic method that logs you into envato marketplaces. yew!
     * Even supports recaptcha if you're loading this script from a web browser :P
     *
     * @param string $username
     * @param string $password
     * @param int $try_number
     * @param bool $data
     * @return bool|int
     */
    public function do_login($username,$password='',$try_number=0,$data=false){

        if($this->logged_in)return true;

        $this->username = $username;
        if($this->waiting_on_recaptcha){
            echo 'Waiting on recaptcha or manual password entry. Run script from browser.';
            return false;
        }
        if(!$data){
            $data = $this->_clean($this->get_url($this->main_marketplace.'/forums',array(),true));
        }
        
        // check if we are logged in or not.
        // simply look for the string logout and Log Out
        if($try_number>1){
            // TODO: handle if envato is down for maintenance
            echo "Unable to login. Sorry, please try again shortly.";
            return false;
        }else if(preg_match('#/sign_out["\?]#',$data)){
            // if sign_out is present on the page then we are logged in
            // new redirect hack with new account centre setup
            $this->logged_in = $this->authenticate_marketplace($this->main_marketplace);
        }else if($username){

            $data = $this->get_url('https://account.envato.com');
//	        echo $data;exit;
            $auth_token = '';
            if(preg_match('#name="authenticity_token" type="hidden" value="([^"]+)"#',$data,$matches)){
                $auth_token = $matches[1];
                if($auth_token){

                    if(isset($_POST['envatopassword'.md5($this->main_marketplace)])){
                        $password = $_POST['envatopassword'.md5($this->main_marketplace)];
                    }
                    if(!$password){
                        // prompt for password
                        $this->waiting_on_recaptcha=true; //re-use this feature from the captcha thingey.
                        ?>
                        <br>
                        <form action="" method="post">
                            Enter Envato Password for account "<?php echo $username;?>": <input type="password" name="envatopassword<?php echo md5($this->main_marketplace);?>"> <br>
                            Enter Envato Two-Factor for account "<?php echo $username;?>" (optional): <input type="text" name="envatopasswordtwofactor<?php echo md5($this->main_marketplace);?>"> <br>
                            <input type="submit" name="go" value="Submit">
                        </form>
                        <?php
                        return 0;
                    }
                    $post_data = array(
                        "username"=>$username,
                        "password"=>$password,
                        "to" => 'codecanyon',
                        "state" => '',
                        "authenticity_token" => $auth_token,
                        "utf8" => '&#x2713;',
                        "commit" => 'Sign+In',
                        "recaptcha_version" => '2',
                        "recaptcha_site_key" => '6LcpTQITAAAAACIt_xhfOTWIZnT66AeAoy5xzgFG',
                        //"from_header_bar"=>"true",
                    );
                    if(isset($_REQUEST['envatopasswordtwofactor'.md5($this->main_marketplace)])){
                        $post_data['authentication_code'] = $_REQUEST['envatopasswordtwofactor'.md5($this->main_marketplace)];
                    }

                    if(isset($_POST['recaptcha'.md5($this->main_marketplace)])){
                        $post_data["recaptcha_challenge_field"]=$_POST['recaptcha'.md5($this->main_marketplace)];
                        $post_data["recaptcha_response_field"]='manual_challenge';
                        unset($_POST['recaptcha'.md5($this->main_marketplace)]);
                    }
                    if(_ENVATO_DEBUG_MODE){
                        echo "Login attempt $try_number with username: ".$username." <br> ";
                    }
                    $url = "https://account.envato.com/sign_in";
                    if($_POST['go'] == 'Submit'){
	                    $data = $this->get_url($url,$post_data,true);
                    } else {
                        $data = $this->get_url($url,$post_data,true);
                    }
                    if(_ENVATO_DEBUG_MODE){
                        file_put_contents(_ENVATO_TMP_DIR."debug-envato_login-".$try_number.".html",$data);
                        echo "Saved LOGIN ATTEMPT file at: "._ENVATO_TMP_DIR."debug-envato_login-".$try_number.".html <br>";
                    }
	                if ( preg_match( '#name="authenticity_token" type="hidden" value="([^"]+)"#', $data, $matches1 ) ) {
		                if ( preg_match( '#name="token" type="hidden" value="([^"]+)"#', $data, $matches2 ) ) {
			                if(_ENVATO_DEBUG_MODE){
		                        echo "Got the verify_token after the /sign_in attempt, processing that callback <br>";
		                    }
			                $post_data = array(
		                        "authenticity_token" => $matches1[1],
		                        "token" => $matches2[1],
		                        "utf8" => '&#x2713;',
		                    );
		                    $data = $this->get_url('http://codecanyon.net/sso/verify_token',$post_data,true);
			                if(_ENVATO_DEBUG_MODE){
		                        file_put_contents(_ENVATO_TMP_DIR."debug-envato_login-".$try_number."-redir-postback.html",$data);
		                        echo "Saved LOGIN ATTEMPT REDIRECT ( http://codecanyon.net/sso/verify_token ) file at: "._ENVATO_TMP_DIR."debug-envato_login-".$try_number."-redir-postback.html <br>";
		                    }
			                if(preg_match('#/sign_out["\?]#',$data)){
					            // if sign_out is present on the page then we are logged in
					            // new redirect hack with new account centre setup
					            $this->logged_in = $this->authenticate_marketplace($this->main_marketplace);
				                return $this->logged_in;
					        }
		                }
	                }
	                $json_test = @json_decode($data,true);
	                if(is_array($json_test) && isset($json_test['state']) && $json_test['state'] == 'ok' && isset($json_test['redirect']) && strlen($json_test['redirect']) > 10){
		                $data_redirect = $this->get_url($json_test['redirect'],array(),true);
		                if(_ENVATO_DEBUG_MODE){
	                        file_put_contents(_ENVATO_TMP_DIR."debug-envato_login-".$try_number."-redir.html",$data_redirect);
	                        echo "Saved LOGIN ATTEMPT REDIRECT ( ".$json_test['redirect']." ) file at: "._ENVATO_TMP_DIR."debug-envato_login-".$try_number."-redir.html <br>";
	                    }
		                if(strpos($data_redirect,'Now signing you into')) {
			                // redirect worked! we're signing in :)
			                /*<form accept-charset="UTF-8" action="verify_token" id="verify_token" method="post"><div style="margin:0;padding:0;display:inline"><input name="utf8" type="hidden" value="&#x2713;" /><input name="authenticity_token" type="hidden" value="P97yE6Vw6fu+igIJkP0PwaEfkGMGCCaQ+yuYmLeLZ9o=" /></div>
  <input id="token" name="token" type="hidden" value="L2MHmS2qRBdMfHJOJQtI4JQiB0GsG6cy8FKzFry5W5SvxoVNqIOl9KgVSiSIla3s" />
  <p>
    Now signing you into CodeCanyon.
    <input name="commit" type="submit" value="Click here" /> if you aren't automatically redirected.
  </p>
</form>*/
			                if ( preg_match( '#name="authenticity_token" type="hidden" value="([^"]+)"#', $data_redirect, $matches1 ) ) {
				                if ( preg_match( '#name="token" type="hidden" value="([^"]+)"#', $data_redirect, $matches2 ) ) {
					                if(_ENVATO_DEBUG_MODE){
						                echo "Got verify_token after json redirect <br>";
					                }
					                $post_data = array(
				                        "authenticity_token" => $matches1[1],
				                        "token" => $matches2[1],
				                        "utf8" => '&#x2713;',
				                    );
					                $new_url = preg_replace('#/callback\?.*$#','/verify_token',$json_test['redirect']);
				                    $data = $this->get_url($new_url,$post_data,true);
					                if(_ENVATO_DEBUG_MODE){
				                        file_put_contents(_ENVATO_TMP_DIR."debug-envato_login-".$try_number."-redir-postback.html",$data);
				                        echo "Saved LOGIN ATTEMPT REDIRECT ( ".$new_url." ) file at: "._ENVATO_TMP_DIR."debug-envato_login-".$try_number."-redir-postback.html <br>";
				                    }
					                if(preg_match('#/sign_out["\?]#',$data)){
							            // if sign_out is present on the page then we are logged in
							            // new redirect hack with new account centre setup
							            $this->logged_in = $this->authenticate_marketplace($this->main_marketplace);
						                return $this->logged_in;
							        }
				                }
			                }
		                }

			                echo "Redirect failed to load with this response: <hr><hr> $data_redirect ";
			                return false;
	                }
                    if(preg_match('#temporarily locked out#',$data)){
                        echo "Sorry, temporarily locked out for too many failed login attempts.";
                        return 0;
                    }else if (preg_match('#recaptcha/api/noscript#',$data)){
                        $this->waiting_on_recaptcha=true;
                        echo "Sorry, too many failed envato login attempts on ".$this->main_marketplace.". Please enter the re-captcha code below. <br>";
                        // <iframe src="https://www.google.com/recaptcha/api/noscript?k=6LeL1wUAAAAAAJ6M4Rd6GzH86I_9_snNaLPqy_ff" h
                        if(preg_match('#<iframe src="https://www.google.com/recaptcha/api/noscript[^"]*"[^>]*>#',$data,$matches)){
                            echo $matches[0].'</iframe>';
                            ?>
                            <br>
                            <form action="" method="post">
                                Enter Code: <input type="text" name="recaptcha<?php echo md5($this->main_marketplace);?>"> <input type="submit" name="go" value="Submit">
                                <?php foreach($_POST as $key=>$val){
                                    if(strpos($key,'recaptcha')!==false || strpos($key,'envatopassword')!==false){
                                    ?>
                                <input type="hidden" name="<?php echo $key;?>" value="<?php echo $val;?>">
                                    <?php
                                    }
                                } ?>
                            </form>
                            <?php
                        }
                        return 0;
                    }
                }else{
                    echo 'failed: no auth token';
                }
            }else{
                echo 'failed. no auth token found on page';
            }
            return $this->do_login($username,$password,$try_number+1,$data);
        }else {
            // no username or password, set, return false so we prompt them to login.
            return false;
        }

        // $data now contains our home page in logged in version.
        // how much cash do we have?
        //<span class="user_balance">$4,829.40</span>
        /*if(preg_match('#class="user_balance">\$([^<]+)<#',$data,$matches)){
            print_r($matches);
            $this->account_balance = preg_replace('/[^\.\d]/','',$matches[1]);
        }*/
        return $this->logged_in;
    }

    /**
     *
     * This method will return an array of purchased items.
     *
     * @param string $url the url from your email e.g. http://codecanyon.net/user/USERNAME?pm_key=OTgxMjYx%0B
     *
     * @return array
     */
    public function verify_email_link($url){
    
        $urlparts = parse_url($url);
        
        $purchases = array();
        
        //login always on the main marketplace
        $data = $this->_clean($this->get_url($this->main_marketplace.$urlparts['path'].'?'.$urlparts['query']));
        
        //if we found some purchased files
        if(preg_match('#<h2 class="underlined">Purchases of your files</h2> <ul class="fancy-list">#s', $data)){
               
               //grab them and put them in an array
               preg_match('#<ul class="fancy-list">(.*)(days?|months?|years?) ago<\/li>(.*)?<\/ul>#s', $data, $hits);
               $raw = explode('<br>', strip_tags(str_replace('</li>', '</li><br>', $hits[0]), '<a><br>'));
               
               foreach($raw as $purchase){
                   
                   preg_match('#href="([^"]+)\/(\d+)"#', $purchase, $hits);
                   
                   if(empty($hits[2])) continue;
                   //get time
                   preg_match('#(\d+) (days?|months?|years?) ago#', $purchase, $time);
                   //get license
                   preg_match('#(Regular|Extended) License#', $purchase, $license);
                   
                   $purchases[] = array(
                       'item_id' => $hits[2],
                       'item_url' => str_replace(array('href="', '"'), array('http://'.$urlparts['host'], ''), $hits[0]),
                       'item_name' => trim(str_replace(array($license[0], $time[0]), '', strip_tags($purchase))),
                       'url' => $url,
                       'text' => trim(strip_tags($purchase)),
                       'license' => $license[1],
                       'date' => date('Y-m-d', strtotime('- '.$time[1].' '.$time[2])),
                   );
                   
               }
               
            if(_ENVATO_DEBUG_MODE){
                echo 'found purchases in '.$url;
                print_r($purchases);
            }
              
        }
        
        return $purchases;

    }

    /**
     *
     * This method will post a comment. Requires the item id and the comment id of the starting comment
     *
     * @param string $url the url from your email e.g. http://codecanyon.net/user/USERNAME?pm_key=OTgxMjYx%0B
     *
     * @return array
     */
    public function post_comment($item_id, $comment_id, $message){
    
        $authenticity_token = $this->get_authenticity_token();
        
        if(!$authenticity_token) return false;
        
        $post = array(
            'utf8' => '&#x2713;',
            'authenticity_token' => $authenticity_token,
            'parent_id' => $comment_id,
            'ret' => 'author_dashboard',
            'content' => $message,
        );
        
        $result = $this->get_url($this->main_marketplace.'/item/goto/'.$item_id.'/comments', $post, false);
        
        return preg_match('#<div class="notice flash">(\s*)<p>Your reply was added<\/p>(\s*)<\/div>#', $result);
       
    }

    /**
     *
     * This method will return the CSV statement for Envato earnings.
     * Useful for manual calculations within your own system.
     * eg: a system that automatically calculates split earnings on collaboration items.
     *
     * @param bool|string $datefrom
     * @param bool|string $dateto
     *
     * @return array
     */
    public function get_statement($datefrom,$dateto=false){

        //if(!$this->logged_in || !$this->username || !$datefrom)return array();

        $items = array();
        $current_month = date('m');
        $current_year = date('Y');
        // work out what dates we need to grab from the statement.
        list($from_month,$from_year) = explode('/',$datefrom);
        $statement_url_requests = array();
        if($from_year<=$current_year && (
            ($from_year==$current_year && $from_month <= $current_month) ||
            ($from_year<$current_year)
        )){
            // we have a valid from date! do the loop.
            $xm = $from_month;
            for($xy=$from_year;$xy<=$current_year;$xy++){
                while(
                    $xm <= 12 && (
                        ($xy==$current_year && $xm<=$current_month) ||
                        ($xy<$current_year)
                    )
                ){
                    $dm = ($xm < 10) ? '0'.$xm : $xm;
                    //$statement_url_requests[] = $this->main_marketplace . "/user/".$this->username."/download_statement_as_csv?month=".$xm.'&year='.$xy;
                    $statement_url_requests[] = $this->main_marketplace . "/statement/".$xy.'-'.$dm.'.csv';
                    $xm++;
                }
                if($xm>12){
                    $xm=1;
                }
            }
        }
        if(_ENVATO_DEBUG_MODE){
            echo 'grabbing these statement urls:';
            print_r($statement_url_requests);
        }

        foreach($statement_url_requests as $url){
            if(strpos($url,$current_year.'-'.$current_month)){
                // we always grab a new copy of the latest months statement:
                // any previous months we always use the cached version if they exist.
                $data = $this->get_url($url,array(),true);
            }else{
                // fall back to cache.
                $data = $this->get_url($url,array(),false);
                if(preg_match('#<html#',$data) && $this->_got_url_from_cache){
                    // we got a cached html file, try again without cache mode just for kicks.
                    $data = $this->get_url($url,array(),true);
                }
            }
            if(preg_match('#<html#',$data)){
		if(_ENVATO_DEBUG_MODE){
                	echo 'failed, probably not logged in correctly, invalid month or envato is temporarily down.';
        	}
                break;
            }
            // save as temp file and use fgetcsv
            // dont want to use str_getcsv because it requires 5.3 and some people are still on 5.2.
            $temp_csv_file = _ENVATO_TMP_DIR.'envato_'.basename($this->username)."_statement-csv-current.csv";
            file_put_contents($temp_csv_file,$data);
            if($temp_csv_file && is_file($temp_csv_file)){
                $fd = fopen($temp_csv_file,"r");
                $count = 1;
                while (($data = fgetcsv($fd, 1000, ",")) !== FALSE) {
                    if(1 == $count){
                        $count++;
                        continue;
                    } // dont save header.
                    if(count($data)<2)continue;
                    $items[]=$data;
                    $count++;
                }
                if(_ENVATO_DEBUG_MODE){
                    echo "Month: $temp_csv_file got $count items<br>";
                }
                fclose($fd);
            }


        }

        foreach($items as &$foo){

            $item_name = str_replace('"','',$foo[2]);
            $item_id = (int)str_replace('"','',$foo[3]);

            $item_type = str_replace('"','',trim($foo[1]));
            $item_amount = 0;
            $item_rate = str_replace('%','',$foo[5]);
            $earnt = str_replace('"','',$foo[4]);
            if($item_type == 'sale'){
                // support the old method of logging stuff:
                if(preg_match('/sold (.*) for (\d.*) w\/ rate of (.*)%/U',str_replace('"','',$foo[3]),$matches)){
                    $item_name = $matches[1];
                    $item_amount = $matches[2];
                    $item_rate = $matches[3];
                    $earnt = str_replace('"','',$foo[2]);
                }else{
                    $item_name = $foo[2];
                    $item_id = (int)$foo[3];
                    $item_amount = $foo[6];
                    $item_rate = str_replace('%','',$foo[5]);
                    $earnt = str_replace('"','',$foo[4]);
                }

            }

            $line = array(
                "type" => $item_type,
                "date" => trim($foo[0]),
                "time" => strtotime(trim($foo[0])),
                "item" => $item_name,
                "item_id" => $item_id,
                'envato_item_id'=>0, // database id.
                "earnt" => $earnt,
                "amount" => $item_amount,
                "rate" => $item_rate,
            );
            $foo = $line;
        }
        if(_ENVATO_DEBUG_MODE){
            echo "There are ".count($items)." lines in your statement CSV file. Is this correct? <br>";
        }
        return $items;
    }

    /**
     *
     * This method will return the recent reviews for your Envato items.
     * $datefrom can be 2014-01-29 (about the time reviews started having usernames)
     * $type can be 1 for ratings and reviews, or 2 for just reviews
     * $item_id can be to limit results to a particular item
     *
     * @param bool|string $datefrom
     * @param bool|int $type
     * @param bool|int $item_id
     *
     * @return array
     */
    public function get_reviews(){ // todo: $datefrom=false,$type=1,$item_id=false

        if(!$this->logged_in)return array();

        $reviews = array();

        if(_ENVATO_DEBUG_MODE){
            echo 'grabbing reviews...';
        }
        $page_number = 1;
        while(true){
            $data = $this->get_url( $this->main_marketplace . "/reviews?page=".$page_number);
            if(!$data || strpos($data,'Page Not Found'))break;
            $data = preg_replace('#\s+#',' ',$data);
            if(preg_match_all('#id="review_\d+".*<div class="review__details"> (.*) on <a href="(/item/[^/]+/)reviews/(\d+)"[^>]+>([^<]+)</a>.*<a href="/ratings/(\d+)"[^>]+>([^<]+)</a>.*</div> </div> <div class="(review|page-controls)"#imsU',$data,$matches)){
                foreach($matches[0] as $match_id => $match){

                    $this_review = array(
                        'rating_id' => $matches[5][$match_id],
                        'rating_url' => '/ratings/'.$matches[5][$match_id],
                        'buyer' => strip_tags($matches[1][$match_id]),
                        'stars' => '',
                        'review' => '',
                        'item_id' => $matches[3][$match_id],
                        'item_name' => $matches[4][$match_id],
                        'item_url' => $matches[2][$match_id].$matches[3][$match_id],
                        'date' => $matches[6][$match_id],
                        'date_estimate' => date('Y-m-d',strtotime('-'.str_replace(' ago','',$matches[6][$match_id]),time())),
                    );

                    if(preg_match_all('#alt="Star-on" class="rating-basic#',$match,$stars)){
                        $this_review['stars'] = count($stars[0]);
                    }
                    if(preg_match('#<div class="review__comments"> <p> <strong>Extra comments from the buyer:</strong><br />(.*)</p>#imsU',$match,$comments)){
                        $this_review['review'] = trim($comments[1]);
                    }

                    $reviews[] = $this_review;

                }

            }else{
                echo 'no matches';
            }
            $page_number++;
        }

        return $reviews;
    }


    /**
     * This method handles all the remote URL gets, and caching.
     *
     * @param string $url Url to get: eg http://themeforest.net/user/dtbaker
     * @param array $post Any post data to send (eg: login details)
     * @param bool $force Force it to refresh, aka: dont read from cache.
     * @return string  HTML data that came back from request.
     */
    private $_got_url_from_cache = false;
    public function get_url($url,$post=array(),$force=true){

        $cache_key = md5(_ENVATO_SECRET . $url . serialize($post));
        $data = ($force) ? false : $this->_get_cache($cache_key);
        if(!$data){
            
           
            $ch=curl_init();
        	curl_setopt($ch, CURLOPT_URL, $url);
        	curl_setopt($ch, CURLOPT_HEADER, 0);
//            curl_setopt($ch, CURLOPT_HEADER, _ENVATO_DEBUG_MODE); // debug
            curl_setopt($ch, CURLINFO_HEADER_OUT, _ENVATO_DEBUG_MODE); // debug
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);            
            $cookies = _ENVATO_TMP_DIR.'cookie-'.md5(_ENVATO_SECRET.$this->username.__FILE__);
        	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookies);
        	curl_setopt($ch, CURLOPT_COOKIEJAR, $cookies);
        	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,0);
        	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        	curl_setopt($ch, CURLOPT_USERAGENT, "dtbakerForum/1.0 (compatible;)");
        	curl_setopt($ch, CURLOPT_VERBOSE, 0);
            
            if($post){
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                
            }

            $data = curl_exec($ch);
            
//            $last_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
//	        echo $last_url;
            
            if(_ENVATO_DEBUG_MODE){
                $headers = curl_getinfo($ch, CURLINFO_HEADER_OUT);
//                echo '<hr>headers for url '.$url.'<br>';var_dump($headers);echo '<hr>';
                file_put_contents(_ENVATO_TMP_DIR."envato_request-".preg_replace('#[^a-z]#','',$url).".html",$data);
                if(preg_match('#Not Allowed#',$data)){
                    echo "Failed with nginx not allowed on request $url with post data:<br>"; print_r($post);
                }
            }
            $this->_save_cache($cache_key,$data);
            $this->_got_url_from_cache=false;
        }else{
            $this->_got_url_from_cache=true;
        }
        return $data;

    }
    /** caching so we don't hit envato too much **/
    private function _get_cache($key){
        if(is_file(_ENVATO_TMP_DIR.'cache-'.basename($key))){
            return @unserialize(file_get_contents(_ENVATO_TMP_DIR.'cache-'.basename($key)));
        }
        return false;
    }
    private function _save_cache($key,$data){
        file_put_contents(_ENVATO_TMP_DIR.'cache-'.basename($key),serialize($data));
        return true;
    }
    // wack everything on 1 line for easier regex scraping
    private function _clean($data){
        $data = preg_replace("/\r|\n/","",$data);
        $data = preg_replace("/\s+/"," ",$data);
        return $data;
    }
    
    /**
     *
     * This method will return the current authenticity_token of the given marketplace.
     *
     * @param string $marketplace
     *
     * @return token
     */
    public function get_authenticity_token($marketplace = ''){
        
        if(empty($marketplace)) $marketplace = $this->main_marketplace;
        
        $marketplace_tag = str_replace('.net','',str_replace('http://','',$marketplace));
        
        return isset($this->authenticity_tokens[$marketplace_tag]) ? $this->authenticity_tokens[$marketplace_tag] : false;
    }


}
