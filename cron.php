<?php

ini_set('display_errors',true);
ini_set('error_reporting',E_ALL);


// simple database.
$data = @json_decode(file_get_contents('data.json'),true);
if(!is_array($data))$data=array();
if(isset($data['lock']) && $data['lock'] > (time() - 3600) && !isset($_REQUEST['skip_lock'])){
	// script already processing, exit to let that one finish.
	exit;
}
$data['lock'] = time();
file_put_contents('data.json',json_encode($data));

require_once 'class.envato_scraper.php';
$envato = new envato_scraper();
$envato->username = 'dtbaker';

// get forum messages from the Admin page.
$url = "http://codecanyon.net/admin";
$html = $envato->get_url($url,array(),true);
if(!$html || strpos($html,'<title>Page Not Found')){
	if(isset($_REQUEST['browser_login'])){
		$envato->do_login($envato->username);
	}else{
		echo "Login required. Please visit this script in a browser to continue.";
	}
}else{
	// got the admin html, parse out recent forum posts.
	$html = preg_replace('#\s+#','',$html);
	preg_match_all('#<li><strong><ahref="([^"]+)"#',$html,$matches);
	if(!isset($data['last_processed_forum_thread_id']))$data['last_processed_forum_thread_id']=0;
	foreach($matches[1] as $forum_thread_url){
		// find the ID of this forum thread and check if we've processed this one yet.
		//$forum_thread_url = 'http://codecanyon.net/forums/thread/right-to-you-you-know-me/170033?page=1';//hack;
		if(preg_match('#forums/thread/[^/]*/([\d]+).*page=(\d+)#',$forum_thread_url,$forum_thread_url_match)){
			if($forum_thread_url_match[1] > $data['last_processed_forum_thread_id'] && $forum_thread_url_match[2] == 1){
				// time to check this thread!
				echo "Checking thread ID: ".$forum_thread_url_match[1] . " with page count of " . $forum_thread_url_match[2]." \n";
				//$data['last_processed_forum_thread_id'] = max($data['last_processed_forum_thread_id'],$forum_thread_url_match[1]);
				$forum_html = $envato->get_url($forum_thread_url,array(),true);
				$forum_html = preg_replace('#\s+#',' ',$forum_html);
				if(preg_match('#<h1 class="page-title">\s?\(Disabled\)#',$forum_html)){
					echo "Already disabled \n";
					continue;
				}
				if(preg_match_all('#class="user-post"(.*)<small title="[^"]+" class="post-avatar__count">(\d+) posts?</small>.*edit-container(.*)</div>#imsU',$forum_html,$forum_html_matches)){
					// we've got a thread!
					// how many posts in this thread?
					if(count($forum_html_matches[1]) <= 2){
						// one post!
						// it's a brand new thread!
						// how many posts does this user have?
						if($forum_html_matches[2][0] <= 3){
							// 3 seems to be the magic baba number.
							if(strpos($forum_html_matches[3][0],'This forum message is waiting for moderation')){
								// we have a winner!
								// this is a spam post.
								// disable it.

								echo "Disablingg this thread!!!! \n";
								continue;

								// post a message to this thread.
								if(preg_match('#^(https?://[^/]+)/#',$forum_thread_url,$marketplace_match)){
									echo "Reply to ".$marketplace_match[1].'/forums/threads/'.$forum_thread_url_match[1].'/reply'."\n";
									if(preg_match('#content="([^"]+)" name="csrf-token"#',$forum_html,$auth_matches)) {
										$post = array(
											'authenticity_token' => $auth_matches[1],
											'utf8'               => '&#x2713;',
											'content'            => 'Thread disabled automatically by dtbaker to prevent spam.',
											'thread_id'          => $forum_thread_url_match[1],
											'__state'            => '',
											'subscribe'          => 'remove',
										);
										$post_reply = $envato->get_url( $marketplace_match[1] . '/forums/threads/' . $forum_thread_url_match[1] . '/reply', $post, true );
										echo "Replied!\n";

										//<a href="/forums/threads/170033/change_flag?field=disabled&amp;value=true" class="btn">Disable<i class="e-icon -icon-disable -margin-left"></i></a>
										echo "Disabling to ".$marketplace_match[1] . '/forums/threads/' . $forum_thread_url_match[1] . '/change_flag?field=disabled&value=true'."\n";
										$disabled_reply = $envato->get_url( $marketplace_match[1] . '/forums/threads/' . $forum_thread_url_match[1] . '/change_flag?field=disabled&value=true', array(), true );
										echo "Disabled! \n";

										$data['disabled'][$forum_thread_url_match[1]] = time();

									}


								}


							}
						}
					}
				}
			}else{
				// already processed or a multi-page thread, ignore.
			}
		}
	}
}

$data['lock'] = 0;
file_put_contents('data.json',json_encode($data));
