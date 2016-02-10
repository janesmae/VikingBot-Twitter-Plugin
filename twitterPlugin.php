<?php

/*
 * Add this to config.php
 *
 * $config['plugins']['twitterAPI'] = array(
 *    'oauth_access_token' => "",
 *    'oauth_access_token_secret' => "",
 *    'consumer_key' => "",
 *    'consumer_secret' => "",
 *    'pollInterval'=>120, // seconds
 *    'dbFile' => 'db/twitterPlugin.db',
 *    'channel'=>'#channel'
 * );
 *
 */


class twitterPlugin implements pluginInterface {

    var $dbFile;
    
    var $config;
    var $socket;
    
    var $lastCleanTime;
    var $started;
	var $todo;
	var $lastMsgSent;
    var $lastMsgWasMe;
    
    var $oauth_access_token;
    var $oauth_access_token_secret;
    var $consumer_key;
    var $consumer_secret;
    
    var $pollInterval;
	var $channel;
    
    /* Init script */
    
    function init($config, $socket) {
        
        $this->config = $config;
        $this->socket = $socket;
        
        $twitterConfig = $config['plugins']['twitterAPI'];
        
        $this->oauth_access_token = (strlen($twitterConfig['oauth_access_token'])>5) ? $twitterConfig['oauth_access_token'] : "";
        $this->oauth_access_token_secret = (strlen($twitterConfig['oauth_access_token_secret'])>5) ? $twitterConfig['oauth_access_token_secret'] : "";
        $this->consumer_key = (strlen($twitterConfig['consumer_key'])>5) ? $twitterConfig['consumer_key'] : "";
        $this->consumer_secret = (strlen($twitterConfig['consumer_secret'])>5) ? $twitterConfig['consumer_secret'] : "";
        
        $this->pollInterval = $twitterConfig['pollInterval'];
        $this->channel = $twitterConfig['channel'];
        $this->dbFile = $twitterConfig['dbFile'];
        
        $this->checkDB();
	 	$this->cleanDB();
        
    }
    
    /* functions not in use but needed to stability */
    
    function onData($data) {}
    function destroy() {}
    function onMessage($from, $channel, $msg) {}

    /* Main function */
    
    function tick() {
        
        if(($this->lastCleanTime + 3600) < time()) {
			$this->cleanDB();
			$this->lastCleanTime = time();
		}

		//Start pollings feeds that should be updated after 20 seconds to get the bot in to any channels etc
		if(($this->started + 30) < time()) {
			$this->twitterRequest();
		}

		//If we got todo, output one row from it
		if(count($this->todo) > 0) {
			if(time() > ($this->lastMsgSent + 5)) {
				$row = array_pop($this->todo);
                sendMessage($this->socket, $row[0], $row[1]);
				$this->lastMsgSent = time();
			}
		}
    
    }
    
    /* Twitter API functionality */
    
    function twitterRequest() {
        
        if(!isset($this->lastCheck) || ($this->lastCheck + $this->pollInterval) < time()) {
				$this->lastCheck = time();
        
            logMsg("!!! twitterPlugin: request");
            $url = "https://api.twitter.com/1.1/statuses/home_timeline.json";
            $oauth = array( 'oauth_consumer_key' => $this->consumer_key,
                        'oauth_nonce' => time(),
                        'oauth_signature_method' => 'HMAC-SHA1',
                        'oauth_token' => $this->oauth_access_token,
                        'oauth_timestamp' => time(),
                        'oauth_version' => '1.0');

            $r = array();
            ksort($oauth);
            foreach($oauth as $key=>$value){
                $r[] = "$key=" . rawurlencode($value);
            }
            $base_info = "GET&" . rawurlencode($url) . '&' . rawurlencode(implode('&', $r));
            $composite_key = rawurlencode($this->consumer_secret) . '&' . rawurlencode($this->oauth_access_token_secret);
            $oauth_signature = base64_encode(hash_hmac('sha1', $base_info, $composite_key, true));
            $oauth['oauth_signature'] = $oauth_signature;

            $r = 'Authorization: OAuth ';
            $values = array();
            foreach($oauth as $key=>$value)
                $values[] = "$key=\"" . rawurlencode($value) . "\"";
            $r .= implode(', ', $values);
            $header = array($r, 'Expect:');

            $options = array( CURLOPT_HTTPHEADER => $header,
                          //CURLOPT_POSTFIELDS => $postfields,
                          CURLOPT_HEADER => false,
                          CURLOPT_URL => $url,
                          CURLOPT_RETURNTRANSFER => true,
                          CURLOPT_SSL_VERIFYPEER => false);

            $feed = curl_init();
            curl_setopt_array($feed, $options);
            $json = curl_exec($feed);
            curl_close($feed);

            $twitter_data = json_decode($json);

            logMsg("!!! twitterPlugin: got feed?");

            foreach($twitter_data as $item) {
                if (isset($item->user)) {
                    $author = $item->user->screen_name;
                    $content = $item->text;
                    $id = $item->id_str;
                    $this->saveEntry($this->channel, $author, $content, $id);
                }
            }
        }
    }
    
    /* Helper functions */
    
    function saveEntry($channel, $author, $content, $id) {
		$content = strip_tags($content);
		$content = str_replace( array("\n","\r"), "", $content);

		$hash = md5($id);
		$data = file($this->dbFile);
		foreach($data as $row) {
			$bits = explode("\t", $row);
			if($hash == @md5($bits[3])) {
				return false; //Already saved
			}
		}
		$data = null;
		$newRow = $channel."\t{$author}\t{$content}\t{$id}\t{$hash}\n";
		$h = fopen($this->dbFile, 'a');
        logMsg("!!! twitterPlugin: Saving database entry");
		fwrite($h, $newRow);
		fclose($h);
		$this->todo[]= array($channel, "@{$author} - {$content} / https://twitter.com/posts/status/{$id}");
		$newRow = null;
	}
    
    function checkDB() {
        logMsg("!!! twitterPlugin: Checking DB");
        if(is_file($this->dbFile) == false) {
            $h = fopen($this->dbFile, 'w+') or die("db folder is not writable!");
			fclose($h);	
		}
	}
    
    function cleanDB() {
        logMsg("!!! twitterPlugin: Cleaning DB");
		$data = file($this->dbFile);
		$data = array_reverse($data);
		if(count($data) > 7500) {
			$newData = array();
			$counter = 0;
			foreach($data as $d) {
				$counter++;
				$newData[] = $d;
				if($counter == 7500) {
					break;
				}
			}
			$h = fopen($this->dbFile, 'w+') or die("db folder is not writable!");
			foreach($newData as $d) {
				if(strlen($d) > 1) {
					fwrite($h, $d."\n");
				}
			}
			fclose($h);
			$newData = null;
		}
		$data = null;
	}

}