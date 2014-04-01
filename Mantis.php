<?php
/**
 * Mantis MediaWiki extension.
 *
 * Mantis Bug Tracker integration
 *
 * Written by Helmut K. C. Tessarek
 * https://github.com/tessus/mwExtensionMantis
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

if ( !defined('MEDIAWIKI') ) 
{
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

$wgExtensionCredits['parserhook'][] = array(
	'path'        => __FILE__,
	'name'        => 'Mantis',
	'author'      => 'Helmut K. C. Tessarek',
	'url'         => 'https://github.com/tessus/mwExtensionMantis',
	'description' => 'Mantis Bug Tracker integration',
	'version'     => '0.9.2'
);

// Configuration variables
$wgMantisConf['DBserver']       = 'localhost'; // Mantis database server
$wgMantisConf['DBport']         = '';          // Mantis database port
$wgMantisConf['DBname']         = '';          // Mantis database name
$wgMantisConf['DBuser']         = '';
$wgMantisConf['DBpassword']     = '';
$wgMantisConf['DBprefix']       = '';          // Table prefix
$wgMantisConf['Url']            = '';          // Mantis Root Page
$wgMantisConf['MaxCacheTime']   = 60*60*0;     // How long to cache pages in seconds
$wgMantisConf['PriorityString'] = '10:none,20:low,30:normal,40:high,50:urgent,60:immediate';                           // $g_priority_enum_string
$wgMantisConf['StatusString']   = '10:new,20:feedback,30:acknowledged,40:confirmed,50:assigned,80:resolved,90:closed'; // $g_status_enum_string
$wgMantisConf['StatusColors']   = '10:fcbdbd,20:e3b7eb,30:ffcd85,40:fff494,50:c2dfff,80:d2f5b0,90:c9ccc4';             // $g_status_colors
//$wgMantisConf['StatusColors']   = '10:ef2929,20:75507b,30:f57900,40:fce94f,50:729fcf,80:8ae234,90:babdb6';             // $g_status_colors
$wgMantisConf['SeverityString'] = '10:feature,20:trivial,30:text,40:tweak,50:minor,60:major,70:crash,80:block';        // $g_severity_enum_string

// create an array from a properly formatted string
function createArray( $string )
{
	$array = array();
	$entries = explode(',', $string);

	foreach ($entries as $entry)
	{
		list($key, $value) = explode(':', $entry);
		$array[$key] = $value;
	}
	
	return $array;
}

// get key or value from an array
function getKeyOrValue( $keyValue, $array )
{
	if (is_numeric($keyValue))
	{
		// get value from key
		if (array_key_exists($keyValue, $array))
		{
			return $array[$keyValue];
		}
		else
		{
			return false;
		}
	}
	else
	{
		// get key from value
		if (in_array($keyValue, $array))
		{
			return array_search($keyValue, $array);
		}
		else
		{
			return false;
		}
	}
}

$wgHooks['ParserFirstCallInit'][] = 'wfMantis';

function wfMantis( &$parser ) 
{
	$parser->setHook( 'mantis', 'renderMantis' );
	return true;
}

// The callback function for converting the input text to HTML output
function renderMantis( $input, $args, $mwParser ) 
{
	global $wgMantisConf;

	if ( $wgMantisConf['MaxCacheTime'] !== false ) 
	{
		$mwParser->getOutput()->updateCacheExpiry($wgMantisConf['MaxCacheTime']);
	}
	//$mwParser->disableCache();

	$columnNames = 'id:b.id,category:c.name,severity:b.severity,status:b.status,username:u.username,created:b.date_submitted,updated:b.last_updated,summary:b.summary';

	$conf['bugid']          = NULL;
	$conf['table']          = 'sortable';
	$conf['header']         = true;
	$conf['color']          = true;
	$conf['status']         = 'open';
	$conf['count']          = NULL;
	$conf['orderby']        = 'b.last_updated'; 
	$conf['order']          = 'desc';
	$conf['dateformat']     = 'Y-m-d';
	$conf['suppresserrors'] = false;

	$tableOptions   = array('sortable', 'standard', 'noborder');
	$orderbyOptions = createArray($columnNames); 

	$statusString   = createArray($wgMantisConf['StatusString']);
	$statusColors   = createArray($wgMantisConf['StatusColors']);
	$priorityString = createArray($wgMantisConf['PriorityString']);
	$severityString = createArray($wgMantisConf['SeverityString']);

	$view = "view.php?id=";

	$parameters = explode("\n", $input);

	foreach ($parameters as $parameter)
	{
		$paramField = explode('=', $parameter, 2);
		if (count($paramField) < 2) 
		{
			continue;
		}
		$type = strtolower(trim($paramField[0]));
		$arg = strtolower(trim($paramField[1]));
		switch ($type) 
		{
			case 'bugid':
				if (is_numeric($arg))
				{
					$conf['bugid']  = intval($arg);
					$conf['color']  = false;
					$conf['header'] = false;
				}
				break;			
			case 'status':
				if (((in_array($arg, $statusString)) !== FALSE ) || $arg == 'open')
				{
					$conf['status'] = $arg;
				}
				break;
			case 'table':
				if ((in_array($arg, $tableOptions)) !== FALSE )
				{
					$conf['table'] = $arg;
				}
				break;
			case 'count':
				if (is_numeric($arg))
				{
					$conf['count'] = intval($arg);
				}
				break;
			case 'order':
				if ($arg == 'asc' || $arg == 'ascending') 
				{
					$conf['order'] = 'asc';
				} 
				else 
				{
					$conf['order'] = 'desc';
				}
				break;
			case 'orderby':
			case 'sortkey':
			case 'ordermethod':
				if (array_key_exists($arg, $orderbyOptions))
				{
					$conf['orderby'] = $orderbyOptions[$arg];
				}
				break;
			case 'suppresserrors':
				if ($arg == 'true' || $arg == 'yes' || $arg == 'on') 
				{
					$conf['suppresserrors'] = true;
				} 
				elseif ($arg == 'false' || $arg == 'no' || $arg == 'off') 
				{
					$conf['suppresserrors'] = false;
				}
				break;
			case 'color':
				if ($arg == 'true' || $arg == 'yes' || $arg == 'on') 
				{
					$conf['color'] = true;
				} 
				elseif ($arg == 'false' || $arg == 'no' || $arg == 'off') 
				{
					$conf['color'] = false;
				}
				break;
			case 'header':
				if ($arg == 'true' || $arg == 'yes' || $arg == 'on') 
				{
					$conf['header'] = true;
				} 
				elseif ($arg == 'false' || $arg == 'no' || $arg == 'off') 
				{
					$conf['header'] = false;
				}
				break;
			default:
				break;
		} // end main switch()
	} // end foreach()

	// build the link url
	if (substr($wgMantisConf['Url'], -1) == '/')
	{
		$link = $wgMantisConf['Url'].$view;
	}
	else
	{
		$link = $wgMantisConf['Url'].'/'.$view;
	}

	// build the SQL query
	$tabprefix = $wgMantisConf['DBprefix'];
	$query = "select b.id as id, c.name as category, b.severity as severity, b.status as status, u.username as username, b.date_submitted as created, b.last_updated as updated, b.summary as summary from ${tabprefix}category_table c inner join ${tabprefix}bug_table b on (b.category_id = c.id) left outer join ${tabprefix}user_table u on (u.id = b.handler_id) ";

	if ($conf['bugid'] == NULL)
	{
		if ($conf['status'] == 'open')
		{
			$status = getKeyOrValue('closed', $statusString);
			$cond = "<> $status";
		}
		else
		{
			$status = getKeyOrValue($conf['status'], $statusString);
			$cond = "= $status";
		}

		$query .= "where b.status $cond ";
		$query .= "order by $conf[orderby] $conf[order] ";

		if (($conf['count'] != NULL) && $conf['count'] > 0)
		{
			$query .= "limit $conf[count]";
		}
	}
	else
	{
		$query .= "where b.id = $conf[bugid]";
	}

	$showColumns = array('id','category','severity','status','updated','summary');
	
	// connect to mantis database
	$db = new mysqli($wgMantisConf['DBserver'], $wgMantisConf['DBuser'], $wgMantisConf['DBpassword'], $wgMantisConf['DBname']);

	/* check connection */
	if ($db->connect_errno)
	{
		$errmsg = sprintf("Connect to [%s] failed: %s\n", $wgMantisConf['DBname'], $db->connect_error);
		return $errmsg;
	}

	if ($result = $db->query($query))
	{
		// check if there are any rows in resultset
		if ($result->num_rows == 0)
		{
			if ($conf['bugid'])
			{
				$errmsg = sprintf("No MANTIS entry (%07d) found.\n", $conf['bugid']);
			}
			else
			{
				$errmsg = sprintf("No MANTIS entries with status '%s' found.\n", $conf['status']);
			}
			$result->free();
			$db->close();
			return $errmsg;
		}

		// create table start
		$output = '{| class="wikitable sortable"'."\n";

		// create table header - use an array to specify which columns to display
		if ($conf['header'])
		{
			foreach ($showColumns as $colname) 
			{
				$output .= "!".ucfirst($colname)."\n";
			}
		}

		$format = "|style=\"padding-left:10px; padding-right:10px; color: black; background-color: #%s; text-align:%s\" |";

		// create table rows
		while ($row = $result->fetch_assoc()) 
		{
			$output .= "|-\n";

			foreach ($showColumns as $colname)
			{
				if ($conf['color'])
				{
					$color = $statusColors[$row['status']];
				}
				else
				{
					$color = "f9f9f9";
				}

				switch ($colname)
				{
					case 'id':
						$output .= sprintf($format, $color, 'center');
						$output .= sprintf("[%s%d %07d]\n", $link, $row[$colname], $row[$colname]);
						break;
					case 'severity':
						$output .= sprintf($format, $color, 'center');
						$output .= getKeyOrValue($row[$colname], $severityString)."\n";
						break;
					case 'status':
						$output .= sprintf($format, $color, 'center');
						$assigned = '';
						if ($username = $row['username'])
						{
							$assigned = "(${username})";
						}
						$output .= sprintf("%s %s\n", getKeyOrValue($row[$colname], $statusString), $assigned);
						break;	
					case 'summary':
						$output .= sprintf($format, $color, 'left');
						$output .= $row[$colname]."\n";
						break;
					case 'updated':
					case 'created':
						$output .= sprintf($format, $color, 'left');
						$output .= date($conf['dateformat'], $row[$colname])."\n";
						break;
					default:
						$output .= sprintf($format, $color, 'center');
						$output .= $row[$colname]."\n";
						break;
				}
			}
		}

		// create table end
		$output .= "|}\n";

		$result->free();
	}

	$db->close();

	//wfMessage("Test Message")->plain();
	return $mwParser->recursiveTagParse($output);
}
?>
