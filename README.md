# CiscoSSH

Class designed to execute commands over SSH connections in Cisco devices (switch, routers, etc.).

It has only been tested with Cisco (hence the name), but could also work with other brands of Network Elements with similar console/OS behaviour.

This implementation is thought to work with the specific case in which the access to each Network Element is done through an Element Manager, instead of direct access to the node receiving the commands.

# Usage

    $ssh      = CiscoSSH::getSSH('172.16.1.1', 'username', 'password', 35); // Connection to Element Manager
    $node     = 
      (object)[
      	'line'            => 'ssh', 		  // or 'telnet'
      	'authentication'  => 'Password', 	// or 'Username:Passsword'
      	'username'        => 'user',	
      	'password'        => 'pass',
      	'ip_address'      => '172.16.0.2',
      ];
      
    // $hostname is passed by reference and will receive 172.16.0.2's configured hostname upon completion.
    $output   = CiscoSSH::exec($ssh, $node, 'show run', $hostname); 
