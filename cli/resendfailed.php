<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This script moves records from logstore_xapi_failed_log to logstore_xapi_log.
 *
 * @package   logstore_xapi
 * @author    Scott Verbeek <scottverbeek@catalyst-au.net>
 * @copyright 2023 Catalyst IT
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', 1);
require_once(__DIR__ . '../../../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/admin/tool/log/store/xapi/lib.php');

$usage = "Resend logs to the {logstore_xapi_log} table from the {logstore_xapi_failed_log} table.

Usage:
    # php resendfailed.php --errortype=401,403
    # php resendfailed.php --datefrom=1698982800 --dateto=1698982823
    # php resendfailed.php --eventname='\\core\\event\\user_loggedin,\\core\\event\\user_loggedout'
    # php resendfailed.php --datefrom=1698982800 --batch=500 --eventname='\\core\\event\\course_viewed' --dryrun=0
    # php resendfailed.php [--help|-h]

Options:
    --errortype=<integer(s)>  Comma seperated error types (integer) to resend, default: all.
    --eventname=<string>      Comma seperated event names to resend, default: all.
    --datefrom=<integer>      Epoch from date to resend, default: null.
    --dateto=<integer>        Epoch to date to resend, default: null.
    --batch=<integer>         The batch size of each move iteration, default: 12500.
    --dryrun=<integer>        Runs the program without executing any write queries, default: 1.
    -h --help                 Print this help.
";

list($options, $unrecognised) = cli_get_params([
    'help' => false,
    'errortype' => null,
    'eventname' => null,
    'datefrom' => null,
    'dateto' => null,
    'dryrun' => true,
    'batch' => 12500,
], [
    'h' => 'help'
]);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln($usage);
    exit(2);
}

$basetable = XAPI_REPORT_SOURCE_FAILED;
$params = [];
$where = [];


// Start of sanitization, and applying of scope.
$options['dryrun'] = boolval($options['dryrun']);
if ($options['dryrun']) {
    cli_write('NOTICE: The program is running in dryrun mode, no write queries will be executed. ');
    cli_write('To disable dryrun mode, add option --dryrun=0' . PHP_EOL);
}

$options['batch'] = intval($options['batch']);
cli_writeln("Program will run in batches of {$options['batch']} records ...");

if (!empty($options['errortype'])) {
    $options['errortype'] = explode(',', $options['errortype']);
    $options['errortype'] = array_map('intval', $options['errortype']);
    list($insql, $inparams) = $DB->get_in_or_equal($options['errortype'], SQL_PARAMS_NAMED, 'errt');
    $where[] = "x.errortype $insql";
    $params = array_merge($params, $inparams);
    cli_writeln('Applied scope for errortype ...');
}

if (!empty($options['eventname'])) {
    $options['eventname'] = explode(',', $options['eventname']);
    list($insql, $inparams) = $DB->get_in_or_equal($options['eventname'], SQL_PARAMS_NAMED, 'evt');
    $where[] = "x.eventname $insql";
    $params = array_merge($params, $inparams);
    cli_writeln('Applied scope for eventname ...');
}

if (!is_null($options['datefrom'])) {
    $options['datefrom'] = intval($options['datefrom']);
    $where[] = 'x.timecreated >= :datefrom';
    $params['datefrom'] = $options['datefrom'];
    cli_writeln('Applied scope for datefrom ...');
}

if (!is_null($options['dateto'])) {
    $options['dateto'] = intval($options['dateto']);
    $where[] = 'x.timecreated <= :dateto';
    $params['dateto'] = $options['dateto'];
    cli_writeln('Applied scope for dateto ...');
}

if (!is_null($options['datefrom']) && !is_null($options['dateto'])) {
    if ($options['datefrom'] > $options['dateto']) {
        cli_writeln(get_string('datetovalidation', 'logstore_xapi'));
        exit(2);
    }
}

if (empty($where)) {
    $where[] = '1 = 1';
    cli_writeln('No scope applied, moving all records ...');
}

$where = implode(' AND ', $where);

$sql = "SELECT x.id
          FROM {{$basetable}} x
         WHERE $where";

$limitfrom = 0;
$limitnum = $options['batch'];
$counttotal = 0;
$countsucc = 0;
$countfail = 0;

do {
    $records = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    $count = count($records);
    $limitfrom += $count;
    $counttotal += $count;
    cli_writeln("Read {$count} records, next offset will be {$limitfrom}");

    $eventids = array_keys($records);

    if (empty($eventids)) {
        continue;
    }

    $mover = new \logstore_xapi\log\moveback($eventids, XAPI_REPORT_ID_ERROR);

    if ($options['dryrun']) {
        continue;
    }

    if ($mover->execute()) {
        $countsucc += $count;
        cli_writeln("$count events successfully sent for reprocessing.");
    } else {
        $countfail += $count;
        cli_writeln("$count events failed to send for reprocessing.");
    }
} while ($count > 0);

cli_writeln("Total of {$counttotal} records matched the scope.");
cli_writeln("Total of {$countsucc} events successfully sent for reprocessing.");
cli_writeln("Total of {$countfail} events failed to send for reprocessing.");
