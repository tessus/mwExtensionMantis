<?php
/**
 * Mantis MediaWiki extension.
 *
 * Mantis Bug Tracker integration
 *
 * Written by Helmut K. C. Tessarek
 *
 * https://www.mediawiki.org/wiki/Extension:Mantis
 * https://github.com/tessus/mwExtensionMantis
 *
 * This program is free software; you can redistribute it  and/or modify
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
	'path'         => __FILE__,
	'name'         => 'Mantis',
	'author'       => '[https://www.mediawiki.org/wiki/User:Tessus Helmut K. C. Tessarek]',
	'url'          => 'https://www.mediawiki.org/wiki/Extension:Mantis',
	'description'  => 'Mantis Bug Tracker integration',
	'license-name' => 'GPL-2.0+',
	'version'      => '1.6'
);

// Configuration variables
$wgMantisConf['DBserver']         = 'localhost'; // Mantis database server
$wgMantisConf['DBport']           = NULL;        // Mantis database port
$wgMantisConf['DBname']           = '';          // Mantis database name
$wgMantisConf['DBuser']           = '';
$wgMantisConf['DBpassword']       = '';
$wgMantisConf['DBprefix']         = '';          // Table prefix
$wgMantisConf['Url']              = '';          // Mantis Root Page
$wgMantisConf['MaxCacheTime']     = 60*60*0;     // How long to cache pages in seconds
$wgMantisConf['PriorityString']   = '10:none,20:low,30:normal,40:high,50:urgent,60:immediate';                           // $g_priority_enum_string
$wgMantisConf['StatusString']     = '10:new,20:feedback,30:acknowledged,40:confirmed,50:assigned,80:resolved,90:closed'; // $g_status_enum_string
$wgMantisConf['StatusColors']     = '10:fcbdbd,20:e3b7eb,30:ffcd85,40:fff494,50:c2dfff,80:d2f5b0,90:c9ccc4';             // $g_status_colors
$wgMantisConf['SeverityString']   = '10:feature,20:trivial,30:text,40:tweak,50:minor,60:major,70:crash,80:block';        // $g_severity_enum_string
$wgMantisConf['ResolutionString'] = '10:open,20:fixed,30:reopened,40:unable to duplicate,50:not fixable,60:duplicate,70:not a bug,80:suspended,90:wont fix'; // $g_resolution_enum_string

// create an array from a properly formatted string
function createArray( $string )
{
	$array = [];
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
	$parser->setHook('mantis', 'renderMantis');
	return true;
}

// check an array against records in a table.
// only return values from that array which also exist in the database
function intersectArrays( $dbcontext, $prefix, $table, $column, $checkArray )
{
	$databaseRecords = [];
	$newArray = [];
	$dbQuery = "select $column from ${prefix}$table";
	if ($result = ${dbcontext}->query($dbQuery))
	{
		while ($row = $result->fetch_assoc())
		{
			$databaseRecords[] = $row[$column];
		}
		$result->close();
	}
	$items = explode(',', $checkArray);
	foreach ($items as $item)
	{
		$item = trim($item);
		if (in_array($item, $databaseRecords))
		{
			$newArray[] = $item;
		}
	}
	if (!empty($newArray))
	{
		return $newArray;
	}
	else
	{
		return NULL;
	}
}

function parseRanges( $items, $rangeOperators )
{
	$newArray = [];

	$op = substr(trim($items[0]), 0, 2);
	$val = substr(trim($items[0]), 2);
	$val = filter_var($val, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_BACKTICK | FILTER_FLAG_ENCODE_AMP);
	if ($val != '')
	{
		$newArray[0]['op'] = $op;
		$newArray[0]['val'] = trim($val);
		if ($items[1])
		{
			// a second range exists
			$op2 = substr(trim($items[1]), 0, 2);
			// if first op starts with g, second op has to start with l; or vice versa
			if (($op{0} == 'g' && $op2{0} == 'l') || ($op{0} == 'l' && $op2{0} == 'g'))
			{
				$val2 = substr(trim($items[1]), 2);
				$val2 = filter_var($val2, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_BACKTICK | FILTER_FLAG_ENCODE_AMP);
				if ($val2 != '' && array_key_exists($op2, $rangeOperators))
				{
					$newArray[1]['op'] = $op2;
					$newArray[1]['val'] = trim($val2);
				}
			}
		}
	}
	if (!empty($newArray))
	{
		return $newArray;
	}
	else
	{
		return NULL;
	}
}

// The callback function for converting the input text to HTML output
function renderMantis( $input, $args, $mwParser )
{
	global $wgMantisConf;

	if ($wgMantisConf['MaxCacheTime'] !== false)
	{
		$mwParser->getOutput()->updateCacheExpiry($wgMantisConf['MaxCacheTime']);
	}

	$columnNames = 'id:b.id,project:p.name,category:c.name,severity:b.severity,priority:b.priority,status:b.status,username:u.username,created:b.date_submitted,updated:b.last_updated,summary:b.summary,fixed_in_version:b.fixed_in_version,version:b.version,target_version:b.target_version,resolution:b.resolution';

	$conf['bugid']             = NULL;
	$conf['table']             = 'sortable';
	$conf['header']            = true;
	$conf['color']             = true;
	$conf['status']            = ['open'];
	$conf['severity']          = NULL;
	$conf['count']             = NULL;
	$conf['orderby']           = 'b.last_updated';
	$conf['order']             = 'desc';
	$conf['dateformat']        = 'Y-m-d';
	$conf['suppresserrors']    = false;
	$conf['suppressinfo']      = false;
	$conf['summarylength']     = NULL;
	$conf['project']           = NULL;
	$conf['category']          = NULL;
	$conf['show']              = ['id','category','severity','status','updated','summary'];
	$conf['comment']           = NULL;
	$conf['fixed_in_version']  = NULL;
	$conf['fixed_in_versionR'] = NULL;
	$conf['version']           = NULL;
	$conf['target_version']    = NULL;
	$conf['username']          = NULL;
	$conf['resolution']        = NULL;
	$conf['headername']        = NULL;
	$conf['align']             = NULL;

	$tableOptions   = ['sortable', 'standard', 'noborder'];
	$orderbyOptions = createArray($columnNames);

	$rangeOperators = ['gt' => '>', 'ge' => '>=', 'lt' => '<', 'le' => '<='];

	$mantis['status']     = createArray($wgMantisConf['StatusString']);
	$mantis['color']      = createArray($wgMantisConf['StatusColors']);
	$mantis['priority']   = createArray($wgMantisConf['PriorityString']);
	$mantis['severity']   = createArray($wgMantisConf['SeverityString']);
	$mantis['resolution'] = createArray($wgMantisConf['ResolutionString']);

	$view = "view.php?id=";

	$parameters = explode("\n", $input);

	foreach ($parameters as $parameter)
	{
		$paramField = explode('=', $parameter, 2);
		if (count($paramField) < 2)
		{
			continue;
		}
		$type  = strtolower(trim($paramField[0]));
		$csArg = trim($paramField[1]);
		$arg   = strtolower(trim($paramField[1]));
		switch ($type)
		{
			case 'bugid':
				$bugid = [];
				$bugids = explode(',', $arg);
				foreach ($bugids as $bug)
				{
					if (is_numeric($bug))
					{
						$bugid[] = intval($bug);
					}
				}
				if (!empty($bugid))
				{
					$conf['bugid'] = $bugid;
					if (count($bugid) == 1)
					{
						$conf['color']  = false;
						$conf['header'] = false;
					}
				}
				break;
			case 'status':
				$arrayNew = [];
				$items = explode(',', $arg);
				foreach ($items as $item)
				{
					$item = trim($item);
					if ((in_array($item, $mantis[$type])) !== FALSE || $item == 'open' || $item == 'all')
					{
						$arrayNew[] = $item;
					}
				}
				if (!empty($arrayNew))
				{
					if (in_array('all', $arrayNew))
					{
						$conf['status'] = NULL;
					}
					else
					{
						$conf['status'] = $arrayNew;
					}
				}
				break;
			case 'table':
				if ((in_array($arg, $tableOptions)) !== FALSE )
				{
					$conf['table'] = $arg;
				}
				break;
			case 'count':
			case 'summarylength':
				if (is_numeric($arg) && ($arg > 0))
				{
					$conf["$type"] = intval($arg);
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
				$tmpOrderBy = $arg;
				$orderbyNew = [];
				$items = explode(',', $tmpOrderBy);
				foreach ($items as $item)
				{
					$orderby = explode(" ", $item);

					// $orderby[0] = column
					// $orderby[1] = order
					if (array_key_exists($orderby[0], $orderbyOptions))
					{
						if (strtolower($orderby[1]) == 'asc' || strtolower($orderby[1]) == 'desc')
						{
							$rcolname = $orderbyOptions[$orderby[0]];
							$orderbyNew[$rcolname] = strtolower($orderby[1]);
						}
						else
						{
							$rcolname = $orderbyOptions[$orderby[0]];
							$orderbyNew[$rcolname] = '';
						}
					}
				}
				if (!empty($orderbyNew))
				{
					// for backwards compat, we have to check if the array has only one element without order
					// if so, set $conf['orderby'] to the key (column reference)
					if (count($orderbyNew) == 1 && ($key = array_search('', $orderbyNew)) != '')
					{
						$conf['orderby'] = $key;
					}
					else
					{
						$conf['orderby'] = $orderbyNew;
					}
				}
				break;
			case 'suppresserrors':
			case 'suppressinfo':
			case 'color':
			case 'header':
				if ($arg == 'true' || $arg == 'yes' || $arg == 'on')
				{
					$conf["$type"] = true;
				}
				elseif ($arg == 'false' || $arg == 'no' || $arg == 'off')
				{
					$conf["$type"] = false;
				}
				break;
			case 'dateformat':
				$conf['dateformat'] = $arg;
				break;
			case 'show':
				$showNew = [];
				$columns = explode(',', $arg);
				foreach ($columns as $column)
				{
					$column = trim($column);
					if (array_key_exists($column, $orderbyOptions))
					{
						$showNew[] = $column;
					}
				}
				if (!empty($showNew))
				{
					$conf['show'] = $showNew;
				}
				break;
			case 'resolution':
			case 'severity':
				$arrayNew = [];
				$items = explode(',', $arg);
				foreach ($items as $item)
				{
					$item = trim($item);
					if ((in_array($item, $mantis[$type])) !== FALSE)
					{
						$arrayNew[] = $item;
					}
				}
				if (!empty($arrayNew))
				{
					$conf[$type] = $arrayNew;
				}
				break;
			case 'project':
				$tmpProjects = $csArg;
				break;
			case 'category':
				$tmpCategories = $csArg;
				break;
			case 'fixed_in_version':
			case 'fixed_in':
				$tmpFixedInVersions = $csArg;
				break;
			case 'version':
				$tmpVersions = $csArg;
				break;
			case 'target_version':
			case 'target':
				$tmpTargetVersions = $csArg;
				break;
			case 'username':
				$tmpUsernames = $csArg;
				break;
			default:
				break;
		} // end main switch()
		// process option: comment
		if (substr($type, 0, 7) == "comment")
		{
			if (is_numeric(substr($type, 8)))
			{
				$id = intval(substr($type, 8));
				$conf['comment'][$id] = $csArg;
			}
		}
		// process option: headername
		if (substr($type, 0, 10) == "headername")
		{
			$column = substr($type, 11);
			if (array_key_exists($column, $orderbyOptions))
			{
				$conf['headername'][$column] = filter_var($csArg, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_BACKTICK | FILTER_FLAG_ENCODE_HIGH | FILTER_FLAG_ENCODE_AMP);
			}
		}
		// process option: align
		if (substr($type, 0, 5) == "align")
		{
			$column = substr($type, 6);
			if (array_key_exists($column, $orderbyOptions))
			{
				switch ($arg)
				{
					case 'l':
					case 'left':
						$conf['align'][$column] = 'left';
						break;
					case 'c':
					case 'center':
						$conf['align'][$column] = 'center';
						break;
					case 'r':
					case 'right':
						$conf['align'][$column] = 'right';
						break;
					default:
						break;
				}
			}
		}
	} // end foreach()

	// build the link url
	$link = NULL;

	if (!empty($wgMantisConf['Url']))
	{
		if (substr($wgMantisConf['Url'], -1) == '/')
		{
			$link = $wgMantisConf['Url'].$view;
		}
		else
		{
			$link = $wgMantisConf['Url'].'/'.$view;
		}
	}

	$tabprefix = $wgMantisConf['DBprefix'];

	// connect to mantis database
	$db = new mysqli($wgMantisConf['DBserver'], $wgMantisConf['DBuser'], $wgMantisConf['DBpassword'], $wgMantisConf['DBname'], $wgMantisConf['DBport']);

	/* check connection */
	if ($db->connect_errno)
	{
		$errmsg = sprintf("Connect to [%s] failed: %s\n", $wgMantisConf['DBname'], $db->connect_error);
		if ($conf['suppresserrors'])
		{
			$errmsg = '';
		}
		return $errmsg;
	}

	$db->set_charset("utf8");

	// create project array - accept only project names that exist in the database to prevent SQL injection
	// this check decreases performance a tiny bit, because we have to make another db call. but security comes first!
	if (!empty($tmpProjects))
	{
		$conf['project'] = intersectArrays($db, $tabprefix, 'project_table', 'name', $tmpProjects);
	}

	// create category array - accept only category names that exist in the database to prevent SQL injection
	// this check decreases performance a tiny bit, because we have to make another db call. but security comes first!
	if (!empty($tmpCategories))
	{
		$conf['category'] = intersectArrays($db, $tabprefix, 'category_table', 'name', $tmpCategories);
	}

	if (!empty($tmpFixedInVersions))
	{
		// check for range filtering first
		$items = explode(',', $tmpFixedInVersions);
		$op = substr(trim($items[0]), 0, 2);

		if (array_key_exists($op, $rangeOperators))
		{
			$conf['fixed_in_versionR'] = parseRanges($items, $rangeOperators);
		}
		else
		{
			// create fixed_in_version array - accept only versions that exist in the database to prevent SQL injection
			// this check decreases performance a tiny bit, because we have to make another db call. but security comes first!
			$conf['fixed_in_version'] = intersectArrays($db, $tabprefix, 'project_version_table', 'version', $tmpFixedInVersions);
		}
	}

	// create version array - accept only versions that exist in the database to prevent SQL injection
	// this check decreases performance a tiny bit, because we have to make another db call. but security comes first!
	if (!empty($tmpVersions))
	{
		$conf['version'] = intersectArrays($db, $tabprefix, 'project_version_table', 'version', $tmpVersions);
	}

	// create target_version array - accept only versions that exist in the database to prevent SQL injection
	// this check decreases performance a tiny bit, because we have to make another db call. but security comes first!
	if (!empty($tmpTargetVersions))
	{
		$conf['target_version'] = intersectArrays($db, $tabprefix, 'project_version_table', 'version', $tmpTargetVersions);
	}

	// create username array - accept only usernames that exist in the database to prevent SQL injection
	// this check decreases performance a tiny bit, because we have to make another db call. but security comes first!
	if (!empty($tmpUsernames))
	{
		$conf['username'] = intersectArrays($db, $tabprefix, 'user_table', 'username', $tmpUsernames);
	}

	// build the SQL query
	$query = "select
		b.id as id,
		p.name as project,
		c.name as category,
		b.severity as severity,
		b.priority as priority,
		b.status as status,
		u.username as username,
		b.date_submitted as created,
		b.last_updated as updated,
		b.summary as summary,
		b.fixed_in_version as fixed_in_version,
		b.version as version,
		b.target_version as target_version,
		b.resolution as resolution
		from
		${tabprefix}category_table c
		inner join ${tabprefix}bug_table b on (b.category_id = c.id)
		inner join ${tabprefix}project_table p on (b.project_id = p.id)
		left outer join ${tabprefix}user_table u on (u.id = b.handler_id) ";

	if ($conf['bugid'] == NULL)
	{
		if ($conf['status'])
		{
			// open and closed = all
			if (in_array('open', $conf['status']) && in_array('closed', $conf['status']))
			{
				$query .= "where 1=1 ";
			}
			// if 'open' is in the list, nothing else matters
			elseif (in_array('open', $conf['status']))
			{
				$closed = getKeyOrValue('closed', $mantis['status']);
				$query .= "where b.status <> $closed ";
			}
			else
			{
				$statusNumbers = [];
				// get the numerical values for status names
				foreach ($conf['status'] as $status)
				{
					$statusNumbers[] = getKeyOrValue($status, $mantis['status']);
				}
				$inlist = implode(",", $statusNumbers);
				$query .= "where b.status in ( $inlist ) ";
			}
		}
		else
		{
			// status = all
			$query .= "where 1=1 ";
		}

		if ($conf['severity'])
		{
			$severityNumbers = [];
			// get the numerical values for severity names
			foreach ($conf['severity'] as $sev)
			{
				$severityNumbers[] = getKeyOrValue($sev, $mantis['severity']);
			}
			$inlist = implode(",", $severityNumbers);
			$query .= "and b.severity in ( $inlist ) ";
		}

		if ($conf['resolution'])
		{
			$resolutionNumbers = [];
			// get the numerical values for resolution names
			foreach ($conf['resolution'] as $res)
			{
				$resolutionNumbers[] = getKeyOrValue($res, $mantis['resolution']);
			}
			$inlist = implode(",", $resolutionNumbers);
			$query .= "and b.resolution in ( $inlist ) ";
		}

		if ($conf['project'])
		{
			$inlist = "'".implode("','", $conf['project'])."'";
			$query .= "and p.name in ( $inlist ) ";
		}

		if ($conf['category'])
		{
			$inlist = "'".implode("','", $conf['category'])."'";
			$query .= "and c.name in ( $inlist ) ";
		}

		if ($conf['fixed_in_versionR'])
		{
			$op1 = $rangeOperators[$conf['fixed_in_versionR'][0]['op']];
			$val1 = $conf['fixed_in_versionR'][0]['val'];
			$query .= "and b.fixed_in_version $op1 $val1 ";

			if ($conf['fixed_in_versionR'][1])
			{
				$op2 = $rangeOperators[$conf['fixed_in_versionR'][1]['op']];
				$val2 = $conf['fixed_in_versionR'][1]['val'];
				$query .= "and b.fixed_in_version $op2 $val2 ";
			}
		}

		if ($conf['fixed_in_version'])
		{
			$inlist = "'".implode("','", $conf['fixed_in_version'])."'";
			$query .= "and b.fixed_in_version in ( $inlist ) ";
		}

		if ($conf['version'])
		{
			$inlist = "'".implode("','", $conf['version'])."'";
			$query .= "and b.version in ( $inlist ) ";
		}

		if ($conf['target_version'])
		{
			$inlist = "'".implode("','", $conf['target_version'])."'";
			$query .= "and b.target_version in ( $inlist ) ";
		}

		if ($conf['username'])
		{
			$inlist = "'".implode("','", $conf['username'])."'";
			$query .= "and u.username in ( $inlist ) ";
		}

		if (!is_array($conf['orderby']))
		{
			$query .= "order by ${conf['orderby']} ${conf['order']} ";
		}
		else
		{
			$orderby = [];
			foreach ($conf['orderby'] as $col => $order)
			{
				$orderby[] = "$col $order";
			}
			$orderlist = implode(',', $orderby);

			$query .= "order by $orderlist ";
		}

		if (($conf['count'] != NULL) && $conf['count'] > 0)
		{
			$query .= "limit ${conf['count']}";
		}
	}
	else
	{
		// I'm a performance guy, so I differentiate between a single row access and an IN list
		// who knows how stupid the database engine is
		if (count($conf['bugid']) == 1)
		{
			$id = $conf['bugid'][0];
			$query .= "where b.id = $id";
		}
		else
		{
			$inlist = implode(',', $conf['bugid']);
			$query .= "where b.id in ( $inlist ) ";
			if (!is_array($conf['orderby']))
			{
				$query .= "order by ${conf['orderby']} ${conf['order']} ";
			}
			else
			{
				$orderby = [];
				foreach ($conf['orderby'] as $col => $order)
				{
					$orderby[] = "$col $order";
				}
				$orderlist = implode(',', $orderby);

				$query .= "order by $orderlist ";
			}
			if (($conf['count'] != NULL) && $conf['count'] > 0)
			{
				$query .= "limit ${conf['count']}";
			}
		}
	}
	if ($result = $db->query($query))
	{
		// check if there are any rows in resultset
		if ($result->num_rows == 0)
		{
			if ($conf['bugid'])
			{
				// only one bugid specified
				if (count($conf['bugid']) == 1)
				{
					$errmsg = sprintf("No MANTIS entry (%07d) found.\n", $conf['bugid'][0]);
				}
				// a list of bugs specified
				else
				{
					$errmsg = sprintf("No MANTIS entries found.\n");
				}
			}
			else
			{
				$useAnd = false;
				$errmsg = "No MANTIS entries with ";

				if ($conf['status'])
				{
					$errmsg .= sprintf("status '%s'", implode(",", $conf['status']));
					$useAnd = true;
				}

				if ($conf['severity'])
				{
					if ($useAnd)
					{
						$errmsg .= " and ";
					}
					$errmsg .= sprintf("severity '%s'", implode(",", $conf['severity']));
					$useAnd = true;
				}

				if ($conf['category'])
				{
					if ($useAnd)
					{
						$errmsg .= " and ";
					}
					$errmsg .= sprintf("category '%s'", implode(",", $conf['category']));
					$useAnd = true;
				}

				if ($conf['project'])
				{
					if ($useAnd)
					{
						$errmsg .= " and ";
					}
					$errmsg .= sprintf("project '%s'", implode(",", $conf['project']));
					$useAnd = true;
				}

				if ($conf['fixed_in_version'])
				{
					if ($useAnd)
					{
						$errmsg .= " and ";
					}
					$errmsg .= sprintf("fixed_in_version '%s'", implode(",", $conf['fixed_in_version']));
					$useAnd = true;
				}

				if ($conf['version'])
				{
					if ($useAnd)
					{
						$errmsg .= " and ";
					}
					$errmsg .= sprintf("version '%s'", implode(",", $conf['version']));
					$useAnd = true;
				}

				if ($conf['target_version'])
				{
					if ($useAnd)
					{
						$errmsg .= " and ";
					}
					$errmsg .= sprintf("target_version '%s'", implode(",", $conf['target_version']));
					$useAnd = true;
				}

				if ($conf['username'])
				{
					if ($useAnd)
					{
						$errmsg .= " and ";
					}
					$errmsg .= sprintf("username '%s'", implode(",", $conf['username']));
					$useAnd = true;
				}

				if ($conf['resolution'])
				{
					if ($useAnd)
					{
						$errmsg .= " and ";
					}
					$errmsg .= sprintf("resolution '%s'", implode(",", $conf['resolution']));
					$useAnd = true;
				}

				$errmsg .= " found.\n";

				if (!$useAnd)
				{
					$errmsg = sprintf("No MANTIS entries found.\n");
				}
			}
			$result->free();
			$db->close();
			if ($conf['suppressinfo'])
			{
				$errmsg = '';
			}
			return $errmsg;
		}

		// create table start
		$output = '{| class="wikitable sortable"'."\n";

		// create table header - use an array to specify which columns to display
		if ($conf['header'])
		{
			foreach ($conf['show'] as $colname)
			{
				$header = ($conf['headername'][$colname] ? $conf['headername'][$colname] : ucfirst($colname));
				$output .= "!".ucfirst($header)."\n";
			}
			if (!empty($conf['comment']))
			{
				$output .= "!Comment\n";
			}
		}

		$format = "|style=\"padding-left:10px; padding-right:10px; color: black; background-color: #%s; text-align:%s\" |";

		// create table rows
		while ($row = $result->fetch_assoc())
		{
			$output .= "|-\n";

			foreach ($conf['show'] as $colname)
			{
				if ($conf['color'])
				{
					$color = $mantis['color'][$row['status']];
				}
				else
				{
					$color = "f9f9f9";
				}

				switch ($colname)
				{
					case 'id':
						$align = ($conf['align'][$colname] ? $conf['align'][$colname] : 'center' );
						$output .= sprintf($format, $color, $align);
						if ($link)
						{
							$output .= sprintf("[%s%d %07d]\n", $link, $row[$colname], $row[$colname]);
						}
						else
						{
							$output .= sprintf("%07d\n", $row[$colname]);
						}
						break;
					case 'severity':
					case 'priority':
					case 'resolution':
						$align = ($conf['align'][$colname] ? $conf['align'][$colname] : 'center' );
						$output .= sprintf($format, $color, $align);
						$output .= getKeyOrValue($row[$colname], $mantis[$colname])."\n";
						break;
					case 'status':
						$align = ($conf['align'][$colname] ? $conf['align'][$colname] : 'center' );
						$output .= sprintf($format, $color, $align);
						$assigned = '';
						if ($username = $row['username'])
						{
							$assigned = "(${username})";
						}
						$output .= sprintf("%s %s\n", getKeyOrValue($row[$colname], $mantis[$colname]), $assigned);
						break;
					case 'summary':
						$align = ($conf['align'][$colname] ? $conf['align'][$colname] : 'left' );
						$output .= sprintf($format, $color, $align);
						$summary = $row[$colname];
						if ($conf['summarylength'] && (strlen($summary) > $conf['summarylength']))
						{
							$summary = trim(substr($row[$colname], 0, $conf['summarylength']))."...";
						}
						$output .= $summary."\n";
						break;
					case 'updated':
					case 'created':
						$align = ($conf['align'][$colname] ? $conf['align'][$colname] : 'left' );
						$output .= sprintf($format, $color, $align);
						$output .= date($conf['dateformat'], $row[$colname])."\n";
						break;
					default:
						$align = ($conf['align'][$colname] ? $conf['align'][$colname] : 'center' );
						$output .= sprintf($format, $color, $align);
						$output .= $row[$colname]."\n";
						break;
				}
			}
			if (!empty($conf['comment']))
			{
				$output .= sprintf($format, $color, 'left');

				if (array_key_exists($row[id], $conf['comment']))
				{
					$output .= $conf['comment'][$row[id]]."\n";
				}
				else
				{
					$output .= "\n";
				}
			}
		}
		// create table end
		$output .= "|}\n";

		$result->free();
	}
	else
	{
		if ($conf['suppresserrors'])
		{
			return '';
		}
		else
		{
			return "Query failed! Check database settings and table prefix! (Missing '_' ?)\n";
		}
	}

	$db->close();

	return $mwParser->recursiveTagParse($output);
}
?>
