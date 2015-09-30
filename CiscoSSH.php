<?php

/**
* CiscoSSH.php
*
* Designed to execute commands over SSH in
* Cisco devices (switches, routers, etc.).
* Only tested with Cisco (hence the name),
* but could also work with other brands or NEs.
*
* Ronald Rey Lovera
* reyronald@gmail.com
* September, 2015
*/

abstract class CiscoSSH
{
	private static $log = '';

	/**
	 * Logs in to a SSH server with the specified credentials,
	 * and returns the Net_SSH2 instance.
	 *
	 * @return 	Net_SSH2 $ssh 
	 * @author 	Ronald Rey
	 **/
	final public static function getSSH($host, $user, $pass, $timeout)
	{
		$ssh = new Net_SSH2($host);
		if (!$ssh->login($user, $pass)) {
		    exit('Login to failed.');
		}
		$ssh->setTimeout($timeout);
		$ssh->write(" ");
		return $ssh;
	}

	/**
	 * Receives an instance of Net_SSH2 that is already logged in
	 * to a router manager, and executes commands in the specified
	 * node.
	 *
	 * @param 	Net_SSH2 &$ssh 
	 *			Instance returned by CiscoSSH::getSSH function.
	 * @param 	object $node 
	 *			Network Element to log in from main SSH device.
	 *			e.g.
	 * 			(object)[
	 *				'line' 			=> 'ssh', 		// or 'telnet'
	 *				'authentication'=> 'Password', 	// or 'Username:Passsword'
	 *				'username' 		=> 'user',	
	 *				'password' 		=> 'pass',
	 *				'ip_address' 	=> '172.16.0.1',
	 *			]
	 * @param 	string|array $commands
	 *			String or array of strings to be executed.
	 * @param 	string? &$hostname
	 *			Will contain the configured hostname of the specified node upon returning.
	 *
	 * @return 	string $output Command(s)'s output from device, slightly tampered with.
	 * @author 	Ronald Rey
	 **/
	final public static function exec(&$ssh, $node, $commands, &$hostname = null)
	{
		static::$log = '';

		// Capturing the hostname with a Regex pattern matching 
		// any string up to a prompt character, in this case either # or >.
		$entry_string = $ssh->read("/(#|>)/", NET_SSH2_READ_REGEX);
		preg_match('/(.*)(#|>)/', $entry_string, $manager_hostname_matches);
		$manager_hostname = isset($manager_hostname_matches[1]) ? $manager_hostname_matches[1] : null;

		// Connecting to NE by typing "telnet|ssh IP_ADDRESS"
		$ssh->write("{$node->line} {$node->ip_address}\n");

		// Typing the Username and Password (telnet) or just Password (ssh)
		if ($node->authentication == "Username:Password" )
			$ssh->write("{$node->username}\n{$node->password}\n");
		elseif ($node->authentication == "Password" )
			$ssh->write("{$node->password}\n");
		else
			throw new Exception('Tipo de autenticación desconodia.');

		// Reading and storing the welcome string up 
		// to the prompt or the Authentication failed message
		$welcome_string = $ssh->read("/(#|>)|(Authentication failed.)/", NET_SSH2_READ_REGEX);

		// Capturing possible responses of the welcome string after our login attempt
		// and acting accordingly. All of the following are unwanted responses.
		if ( 
			strpos($welcome_string, "% Connection timed out; remote host not responding") !== false ||
			strpos($welcome_string, "% Connection refused by remote host") !== false 
			)			
			static::log("{$node->ip_address} host down" . PHP_EOL);		
		elseif (strpos($welcome_string, "Authentication failed") !== false ) {
			static::log("{$node->ip_address} Authentication failed." . PHP_EOL);
			$ssh->write("\x03");
			$ssh->read("/(#|>)/", NET_SSH2_READ_REGEX);
			continue;
		}

		// Capturing the hostname with a Regex pattern matching 
		// any string up to a prompt character, in this case either # or >.
		preg_match('/(.*)(#|>)/', $welcome_string, $hostname_matches);
		$hostname = isset($hostname_matches[1]) ? $hostname_matches[1] : null;

		// This is the capture of the whole config.
		// After executing the "show run" command, we are going 
		// to tell our socket to keep reading until they find either:
		// 1. "--More--", 
		// 2. "end" (which would correspond to the end of the config) or 
		// 3. the hostname of the current device
		// The regex pattern tries to match any of these possibilities 
		// while being careful not to hit the wrong "end" string 
		// (hence the special chars before and after seen in the pattern).
		// Also, we are going to send a SPACE char all the way until the end.
		$output = '';
		if ( !is_array($commands) )
			$commands = [ $commands ];

		$escaped_hostname = str_replace("/", "\/", $hostname);
		$escaped_manager_hostname = str_replace("/", "\/", $manager_hostname);
		$endingDelimeters = [
			"\nend\r\n",
			"\bend\r\n",
			"$escaped_hostname([\w()\-]*)#|>",
			"$escaped_manager_hostname([\w()\-]*)#|>"
		];
		foreach ($commands as $command) {
			$ssh->write( $command . "\n");
			while ( $section = $ssh->read("/--More--|" . implode("|", $endingDelimeters) . "/", NET_SSH2_READ_REGEX) ) {
				// This condition is met when the current user has no access
				// to the "show run" command or the command is not available in the device.
				if ( strpos($output, "Invalid input detected") !== false )
					static::log("{$node->ip_address} comando `{$command}` no disponible.". PHP_EOL);

				$output .= $section;
				$ssh->write(" ");
				if ( preg_match("/" . implode("|", $endingDelimeters) . "/", $section) )
					break;
			}
			$ssh->read("/(#|>)/", NET_SSH2_READ_REGEX);
		}
		// Exiting out of this NE, to continue with another one
		// in our next iteration of this loop
		$ssh->write(" exit\r\n");

		// Let's clean the output containing all of the configuration by:
		// 1. removing the "show run" command string
		// 2. removing all "--More--" strings up to the next valid character.
		// 3. removing special characters and "[K", which for some reason is present in some outputs
		$output = trim(str_replace(["show run\r\n"], "", $output));
		$output = preg_replace("/ --More--.+?(?=([*!\nA-z0-9]))/", "", $output);
		$output = str_replace([chr(27), "[K"], "", $output);

		return $output;
	}

	final private static function log($msg) 
	{
		static::$log .= $msg . PHP_EOL;
		echo $msg . PHP_EOL;
	}

	final public static function getLog()
	{
		return static::$log;
	}
}
