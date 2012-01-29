<?php

require_once( config_get( 'class_path' ) . 'MantisPlugin.class.php' );

class NotifyJabberPlugin extends MantisPlugin {
    function register() {
        $this->name = 'NotifyJabber';
        $this->description = "Jabber notification on new notes";
        $this->page = 'config';
        $this->version = '0.0.1';
        $this->requires = array(
            'MantisCore' => '1.2',
        );
        $this->author = 'Tamás Gulácsi';
        $this->contact = 'gt-dev AT NOSPAM DOT gthomas DOT homelinux DOT org';
    }

    /**
     * Schema
     */
    function schema ()
    {
        return array(
            array('CreateTableSQL', array(plugin_table('userjabaddr'), "
                user_id     I UNSIGNED NOTNULL PRIMARY,
                address  XL")),
        );
    }

    function config() {
      //require_once( dirname(__FILE__) . '/config.php' );
        return array(
            'host' => 'talk.google.com',
            'port' => 5222,
            'username' => 'username',
            'password' => 'password',
	    'resource' => 'xmpp',
	    'server' => NULL,
        );
    }

    function hooks() {
        return array(
            'EVENT_MENU_MANAGE' => 'manage',
	    'EVENT_BUGNOTE_ADD' => 'bugnote_added',
	    'EVENT_ACCOUNT_PREFS_UPDATE_FORM' => 'pref_rows',
	    'EVENT_ACCOUNT_PREFS_UPDATE' => 'prefs_update',
        );
    }

    function manage( ) {
        require_once( 'core.php' );

        if ( access_get_project_level() >= MANAGER) {
           return array( '<a href="' . plugin_page( 'config.php' ) . '">'
                .  plugin_lang_get('config') . '</a>', );
        }
    }


    function sendmsg( $userids, $msg ) {
        require_once( dirname(__FILE__) . '/lib/XMPP/XMPP.php' );

	$host = plugin_config_get( 'host' );
	$port = plugin_config_get( 'port' );
	$username = plugin_config_get( 'username' );
	$password = plugin_config_get( 'password' );
	$resource = plugin_config_get( 'resource' );
	$server = plugin_config_get( 'server' );

	$conn = new XMPP($host, $port, $username, $password, $resource, $server, $printlog=False, $loglevel=LOGGING_DEBUG);
	$conn->connect();
	$conn->processUntil('session_start');
	if( !is_array($userids) )
	  $userids = array($userids);
	else
	  $userids = array_unique( $userids );
	foreach( $userids as $userid ) {
	  $addr = userid2jabaddr($userid);
	  $conn->message($addr, $msg);
	}
	$conn->disconnect();
    }

    function userid2jabaddr( $userid ) {
        require_once( 'core.php' );
	require_api( 'database_api.php' );
	$tbl = plugin_table( 'userjabaddr' );
	$t_qry = 'SELECT address FROM '.$tbl.' WHERE userid = '.db_prepare_int($userid);
	$t_result = db_query( $t_qry, 1 );
	while( !$t_result->EOF ) {
	  $row = db_fetch_array( $t_result );
	  if( $row === false )
            break;
	  return $row['address'];
	}
	return NULL;
    }

    function bugnote_added( $bug_id, $bugnote_id ) {
      require_once( 'core.php' );
      require_api( 'bug_api.php' );
      require_api( 'bugnote_api.php' );
      require_api( 'user_api.php' );
      $t_reporter_id = bugnote_get_field($bugnote_id, 'reporter_id');
      $t_reporter_name = user_get_realname( $t_reporter_id );
      function not_reporter($x_id) {
	return $x_id !== $t_reporter_id;
      }
      $t_url = preg_replace( '!/(plugin.*)\.php.*$!', "view.php?id=${bug_id}#c${bugnote_id}", getenv('script_uri') );
      $userids = array_filter( $userids, 'not_reporter' );
      $note_text = bugnote_get_text($bugnote_id);
      $userids = bug_get_monitors($bug_id);
      $msg = "User $t_reporter_name sent the following on $t_url:\n$note_text";

      sendmsg($userids, $msg);
    }

    function pref_rows( $userid ) {
      require_once( 'core.php' );
      require_once( 'user_pref_api.php' );
      $t_addr = userid2jabaddr($userid);
      ?>
<tr <?php echo helper_alternate_class() ?>>
	<td class="category">
		<?php echo lang_get( 'jabber_address' ) ?>
	</td>
	<td>
		<input type="text" name="jabber_address" value="<?php echo $t_addr ?>" /> <?php echo lang_get( 'minutes' ) ?>
	</td>
</tr>
	<?php
    }

    function prefs_update( $userid ) {
      require_once( 'core.php' );
      require_api( 'database_api.php' );
      $f_addr = gpc_get_string('jabber_address');
      $t_addr = db_prepare_string( $f_addr );
      $t_userid = db_prepare_int( $userid );

      $ok = db_query( "UPDATE $t_tbl SET address = $t_addr WHERE user_id = $t_userid" );
      if( !$ok || db_affected_rows() < 1 )
	db_query( "INSERT INTO $t_tbl (user_id, address) VALUES ($t_userid, $t_addr)" );
    }
}
