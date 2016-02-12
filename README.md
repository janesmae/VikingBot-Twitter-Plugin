# VikingBot-Twitter-Plugin

A plugin for posting Twitter feed to IRC

## Configure

Add the following to the config.php:

```php
$config['plugins']['twitterAPI'] = array(
    'oauth_access_token' => "",
    'oauth_access_token_secret' => "",
    'consumer_key' => "",
    'consumer_secret' => "",
    'pollInterval'=>120, // seconds
    'dbFile' => 'db/twitterPlugin.db',
    'channel'=>'#channel'
);
```