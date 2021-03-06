#!/usr/local/bin/php
<?php

/*
    Copyright (C) 2008 Shrew Soft Inc. <mgrooms@shrew.net>
    Copyright (C) 2010 Ermal Luçi
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
       this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
       notice, this list of conditions and the following disclaimer in the
       documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.
*/

/*
 * OpenVPN calls this script to authenticate a user
 * based on a username and password. We lookup these
 * in our config.xml file and check the credentials.
 */

require_once("config.inc");
require_once("auth.inc");
require_once("util.inc");
require_once("interfaces.inc");

/* setup syslog logging */
openlog("openvpn", LOG_ODELAY, LOG_AUTH);

if (count($argv) > 6) {
    $authmodes = explode(',', $argv[5]);
    $username = base64_decode(str_replace('%3D', '=', $argv[1]));
    $password = base64_decode(str_replace('%3D', '=', $argv[2]));
    $common_name = $argv[3];
    $modeid = $argv[6];
    $strictusercn = $argv[4] == 'false' ? false : true;
}

if (!$username || !$password) {
    syslog(LOG_ERR, "invalid user authentication environment");
    closelog();
    exit(-1);
}

/* Replaced by a sed with propper variables used below(ldap parameters). */
//<template>

if (file_exists("/var/etc/openvpn/{$modeid}.ca")) {
    putenv("LDAPTLS_CACERT=/var/etc/openvpn/{$modeid}.ca");
    putenv("LDAPTLS_REQCERT=never");
}

$authenticated = false;
if (($strictusercn === true) && ($common_name != $username)) {
    syslog(LOG_WARNING, "Username does not match certificate common name ({$username} != {$common_name}), access denied.\n");
    closelog();
    exit(1);
}

if (!is_array($authmodes)) {
    syslog(LOG_WARNING, "No authentication server has been selected to authenticate against. Denying authentication for user {$username}");
    closelog();
    exit(1);
}

$a_server = null;

if (isset($config['openvpn']['openvpn-server'])) {
    foreach ($config['openvpn']['openvpn-server'] as $server) {
        if ("server{$server['vpnid']}" == $modeid) {
            $a_server = $server;
            break;
        }
   }
}

if ($a_server == null) {
    syslog(LOG_WARNING, "OpenVPN '$modeid' was not found. Denying authentication for user {$username}");
    closelog();
    exit(1);
}

if (!empty($a_server['local_group']) && !in_array($a_server['local_group'], getUserGroups($username))) {
    syslog(LOG_WARNING, "OpenVPN '$modeid' requires the local group {$a_server['local_group']}. Denying authentication for user {$username}");
    closelog();
    exit(1);
}

foreach ($authmodes as $authmode) {
    $authcfg = auth_get_authserver($authmode);

    /* XXX this doesn't look right... */
    if (!$authcfg && $authmode != "local") {
        continue;
    }

    $authenticated = authenticate_user($username, $password, $authcfg);
    if ($authenticated == true) {
        break;
    }
}

if ($authenticated == false) {
    syslog(LOG_WARNING, "user '{$username}' could not authenticate.\n");
    closelog();
    exit(-1);
}

syslog(LOG_NOTICE, "user '{$username}' authenticated\n");
closelog();

exit(0);
