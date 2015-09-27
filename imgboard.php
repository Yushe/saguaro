<?php
session_start();
/*
=================================
===Saguaro Imageboard Software===
=================================
>>1.0
http://saguaroimgboard.tk/download/
the above link will have the latest version.

This is a branch off of futallaby and is currently in development because
I felt like doing this and have always prefered imageboards to phpbb clones.

Special thanks to !KNs1o0VDv6, Glas, Anonymous from vchan, RePod, and anyone who actually uses this.
If you need help you can reach me at spoot@saguaroimgboard.tk
or http://saguaroimgboard.tk/sug/
or if you would like to help development and have php experience.
If you need help setting saguaro up, check http://saguaroimgboard.tk/suprt/
Remember to look through older threads and see if your problem wasn't solved already!

*/
require "config.php";

$host = $_SERVER['REMOTE_ADDR'];

extract( $_POST );
extract( $_GET );
extract( $_COOKIE );

$path = realpath( "./" ) . '/' . IMG_DIR;
ignore_user_abort( TRUE );
$badstring = array(
     "nimp.org"
); // Refused text
$badfile   = array(
     "dummy",
    "dummy2"
); //Refused files (md5 hashes)


function mysql_call( $query ) {
    $ret = mysql_query( $query );
    if ( !$ret ) {
	if ( DEBUG_MODE ) {
	        echo "Error on query: " . $query . "<br />";
	        echo mysql_error() . "<br />";
    	} else {
	        echo "MySQL error!<br />";
    	}
    }
    return $ret;
}

//check for SQL table existance


$con  = mysql_connect( SQLHOST, SQLUSER, SQLPASS );

if ( !$con ) {
    echo S_SQLCONF; //unable to connect to DB (wrong user/pass?)
    exit;
}

$db_id = mysql_select_db( SQLDB, $con );
if ( !$db_id ) {
    echo S_SQLDBSF;
}

function rebuildqueue_create_table() {
    $sql = <<<EOSQL
CREATE TABLE `rebuildqueue` (
  `board` char(4) NOT NULL,
  `no` int(11) NOT NULL,
  `ownedby` int(11) NOT NULL default '0',
  `ts` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
  PRIMARY KEY  (`board`,`no`,`ownedby`)
)
EOSQL;
    mysql_call( $sql );
}

function rebuildqueue_add( $no ) {
    $board = BOARD_DIR;
    $no    = (int) $no;
    for ( $i = 0; $i < 2; $i++ )
        if ( !mysql_call( "INSERT IGNORE INTO rebuildqueue (board,no) VALUES ('$board','$no')" ) )
            rebuildqueue_create_table();
        else
            break;
}

function rebuildqueue_remove( $no ) {
    $board = BOARD_DIR;
    $no    = (int) $no;
    for ( $i = 0; $i < 2; $i++ )
        if ( !mysql_call( "DELETE FROM rebuildqueue WHERE board='$board' AND no='$no'" ) )
            rebuildqueue_create_table();
        else
            break;
}

function rebuildqueue_take_all() {
    $board = BOARD_DIR;
    $uid   = mt_rand( 1, mt_getrandmax() );
    for ( $i = 0; $i < 2; $i++ )
        if ( !mysql_call( "UPDATE rebuildqueue SET ownedby=$uid,ts=ts WHERE board='$board' AND ownedby=0" ) )
            rebuildqueue_create_table();
        else
            break;
    $q     = mysql_call( "SELECT no FROM rebuildqueue WHERE board='$board' AND ownedby=$uid" );
    $posts = array();
    while ( $post = mysql_fetch_assoc( $q ) )
        $posts[] = $post['no'];
    return $posts;
}

//Log
require_once(CORE_DIR . "/log/log.php");
$my_log = new Log;
function updatelog($resno = 0, $rebuild = 0) {
    global $my_log;

    $my_log->update($resno, $rebuild);
}
function log_cache($invalidate = 0) {
    global $my_log;

    $my_log->update_cache();
    $log = $my_log->cache;
}

// check whether the current user can perform $action (on $no, for some actions)
// board-level access is cached in $valid_cache.
function valid( $action = 'moderator', $no = 0 ) {
	require_once(CORE_DIR . "/admin/validate.php");

	$validate = new Validation;
	return $validate->verify( $action );
}

/* head */
function head() {
    require_once(CORE_DIR . "/general/head.php");

    $head = new Head;
    return $head->generate();
}

/* Contribution form */
function form( &$dat, $resno, $admin = "" ) {
    require_once(CORE_DIR . "/postform.php");

    $postform = new PostForm;
    $dat .= $postform->format($resno, $admin);
}

/* Footer */
function foot( &$dat ) {
    if (file_exists(BOARDLIST))
        $dat .= '<span class="boardlist">' . file_get_contents( BOARDLIST ) . '</span>';

    $dat .= '<div class="footer">' . S_FOOT . '</div><a href="#bottom" /></a></body></html>';
}

function error( $mes, $dest = '', $fancy = 0 ) {
    global $path;
    $upfile_name = $_FILES["upfile"]["name"];
    if ( is_file( $dest ) )
        unlink( $dest );
    $dat .= head();
    echo $dat;
    if ( $mes == S_BADHOST ) {
        die( "<html><head><meta http-equiv=\"refresh\" content=\"0; url=banned.php\"></head></html>" );
    } elseif (!$fancy) {
        echo "<br /><br /><hr size=1><br /><br />
		   <center><font color=blue size=5>$mes<br /><br /><a href=" . PHP_SELF2_ABS . ">" . S_RELOAD . "</a></b></font></center>
		   <br /><br /><hr size=1>";
        die( "</body></html>" );
    }
}

/* Regist */
function regist( $name, $email, $sub, $com, $url, $pwd, $resto ) {
    require_once(CORE_DIR . "/regist/regist.php");
}

function proxy_connect( $port ) { /*A copy of this exists in the function hell,
it's good to be straight up deleted when it is removed from regist*/
    $fp = @fsockopen( $_SERVER["REMOTE_ADDR"], $port, $a, $b, 2 );
    if ( !$fp ) {
        return 0;
    } else {
        return 1;
    }
}

// deletes a post from the database
// imgonly: whether to just delete the file or to delete from the database as well
// automatic: always delete regardless of password/admin (for self-pruning)
// children: whether to delete just the parent post of a thread or also delete the children
// die: whether to die on error
// careful, setting children to 0 could leave orphaned posts.
function delete_post( $resno, $pwd, $imgonly = 0, $automatic = 0, $children = 1, $die = 1 ) {
	require_once(CORE_DIR . "/admin/delpost.php");

	$remove = new DeletePost;
	$remove->targeted( $resno, $pwd, $imgonly = 0, $automatic = 0, $children = 1, $die = 1 );
}

/* user image deletion */
function usrdel( $no, $pwd ) {
	global $path, $pwdc, $onlyimgdel;
	require_once(CORE_DIR . "/admin/delpost.php");
	
	$del = new DeletePost;
	$del->userDel($no, $pwd);
}
	
function report()
	{
		require_once(CORE_DIR . "/admin/report.php");
		$report = new Report;
		
		if ( $_SERVER['REQUEST_METHOD'] == 'GET' ) {
			$no = $_GET['no'];
			//Various checks in the popup window before form is filed
			if ( !$report->report_post_exists( $no ) ) 
				$report->error('That post doesn\'t exist anymore.', $no);
			if ( $report->report_post_isSticky( $no ) ) 
				$report->error('Stop trying to report a sticky.', $no);
			$report->report_check_ip( BOARD_DIR, $no );
			$report->form_report( BOARD_DIR, $_GET['no'] );			//User passed checks, display form

		} else {
			//Report form has been filled out, POST'ed and can now be filed
			$report->report_check_ip( BOARD_DIR, $_POST['no'] );
			$report->report_submit( BOARD_DIR, $_POST['no'], $_POST['cat'] );
		}
		die( '</body></html>' );
	}

//Called when someone tries to visit imgboard.php?res=[[[postnumber]]]
function resredir( $res ) {
    $res = (int) $res;

    if ( !$redir = mysql_call( "select no,resto from " . SQLLOG . " where no=" . $res ) ) {
        echo S_SQLFAIL;
    }
    list( $no, $resto ) = mysql_fetch_row( $redir );
    if ( !$no ) {
        $maxq = mysql_call( "select max(no) from " . SQLLOG . "" );
        list( $max ) = mysql_fetch_row( $maxq );
        if ( !$max || ( $res > $max ) )
            header( "HTTP/1.0 404 Not Found" );
        else // res < max, so it must be deleted!
            header( "HTTP/1.0 410 Gone" );
        error( S_NOTHREADERR, $dest );
    }

    if ( $resto == "0" ) // thread
        $redirect = DATA_SERVER . BOARD_DIR . "/res/" . $no . PHP_EXT . '#' . $no;
    else
        $redirect = DATA_SERVER . BOARD_DIR . "/res/" . $resto . PHP_EXT . '#' . $no;


    echo "<META HTTP-EQUIV=\"refresh\" content=\"0;URL=$redirect\">";
    if ( $resto == "0" )
        log_cache();


    if ( $resto == "0" ) { // thread
        updatelog( $res );
    }
}

function rebuild( $all = 0 ) {
    if ( !valid( 'moderator' ) )
        die( 'Update failed...' );

    header( "Pragma: no-cache" );
    echo "Rebuilding ";
    if ( $all ) {
        echo "all";
    } else {
        echo "missing";
    }
    echo " replies and pages... <a href=\"" . PHP_SELF2_ABS . "\">Go back</a><br><br>\n";
    ob_end_flush();
    $starttime = microtime( true );
    if ( !$treeline = mysql_call( "select no,resto from " . SQLLOG . " where root>0 order by root desc" ) ) {
        echo S_SQLFAIL;
    }
    log_cache();
    echo "Writing...\n";
    if ( $all || !defined( 'CACHE_TTL' ) ) {
        while ( list( $no, $resto ) = mysql_fetch_row( $treeline ) ) {
            if ( !$resto ) {
                updatelog( $no, 1 );
                echo "No.$no created.<br>\n";
            }
        }
        updatelog();
        echo "Index pages created.<br>\n";
    } else {
        $posts = rebuildqueue_take_all();
        foreach ( $posts as $no ) {
            $deferred = ( updatelog( $no, 1 ) ? ' (deferred)' : '' );
            if ( $no )
                echo "No.$no created.$deferred<br>\n";
            else
                echo "Index pages created.$deferred<br>\n";
        }
    }
    $totaltime = microtime( true ) - $starttime;
    echo "<br>Time elapsed (lock excluded): $totaltime seconds", "<br>Pages created.<br><br>\nRedirecting back to board.\n<META HTTP-EQUIV=\"refresh\" content=\"10;URL=" . PHP_SELF2 . "\">";
}

/*-----------Main-------------*/
switch ( $mode ) {
    case 'regist':
        regist( $name, $email, $sub, $com, '', $pwd, $resto );
        break;
    case 'rebuild':
        rebuild();
        break;
    case 'rebuildall':
        rebuild( 1 );
        break;
	case 'report':
		report();
		break;
    case 'usrdel':
        usrdel( $no, $pwd );
        break;
    default:
        if ( $res ) {
            resredir( $res );
            echo "<META HTTP-EQUIV=\"refresh\" content=\"10;URL=" . PHP_SELF2_ABS . "\">";
        } else {
            echo "Updating index...\n";
            updatelog();
            echo "<META HTTP-EQUIV=\"refresh\" content=\"0;URL=" . PHP_SELF2_ABS . "\">";
        }
}
?>
