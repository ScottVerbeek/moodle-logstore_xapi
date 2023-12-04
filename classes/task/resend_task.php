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

namespace logstore_xapi\task;

require_once($CFG->dirroot . '/admin/tool/log/store/xapi/lib.php');

/**
 * Schedules a adhoc task resendadhoc.
 *
 * @package   logstore_xapi
 * @copyright Scott Verbeek <scottverbeek@catalyst-au.net>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resend_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskresend', 'logstore_xapi');
    }

    /**
     * List a adhoc task that will resend the items.
     */
    public function execute() {
        global $DB;

        $settings = new \stdClass();
        $settings->errortype = (string) get_config('logstore_xapi', 'resendtaskerrortype');
        $settings->eventname = (string) get_config('logstore_xapi', 'resendeventname');
        $settings->datefrom = (int) get_config('logstore_xapi', 'resenddatefrom');
        $settings->dateto = (int) get_config('logstore_xapi', 'resenddateto');
        $settings->runtime = (int) get_config('logstore_xapi', 'resendtaskruntime');
        $settings->batch = (int) get_config('logstore_xapi', 'resendbatch');

        $basetable = XAPI_REPORT_SOURCE_FAILED;
        $params = [];
        $where = [];

        // Start of sanitization, and applying of scope.
        mtrace("Task will stop after {$settings->runtime} seconds have passed, started at " .date('Y-m-d H:i:s T') . "...");
        $starttime = microtime(true);

        mtrace("Task will run in batches of {$settings->batch} records ...");

        if (!empty($settings->errortype)) {
            $settings->errortype = explode(',', $settings->errortype);
            $settings->errortype = array_map('intval', $settings->errortype);
            list($insql, $inparams) = $DB->get_in_or_equal($settings->errortype, SQL_PARAMS_NAMED, 'errt');
            $where[] = "x.errortype $insql";
            $params = array_merge($params, $inparams);
            mtrace('Applied scope for errortype ...');
        }

        if (!empty($settings->eventname)) {
            $settings->eventname = explode(',', $settings->eventname);
            list($insql, $inparams) = $DB->get_in_or_equal($settings->eventname, SQL_PARAMS_NAMED, 'evt');
            $where[] = "x.eventname $insql";
            $params = array_merge($params, $inparams);
            mtrace('Applied scope for eventname ...');
        }

        if (!empty($settings->datefrom) && $settings->datefrom !== false) {
            $where[] = 'x.timecreated >= :datefrom';
            $params['datefrom'] = $settings->datefrom;
            mtrace('Applied scope for datefrom ...');
        }

        if (!empty($settings->dateto) && $settings->dateto !== false) {
            $where[] = 'x.timecreated <= :dateto';
            $params['dateto'] = $settings->dateto;
            mtrace('Applied scope for dateto ...');
        }

        if (!empty($settings->datefrom) && !empty($settings->dateto)) {
            if ($settings->datefrom > $settings->dateto) {
                mtrace(get_string('datetovalidation', 'logstore_xapi'));
                exit(2);
            }
        }

        if (empty($where)) {
            $where[] = '1 = 1';
            mtrace('No scope applied, moving all records ...');
        }

        $where = implode(' AND ', $where);

        $sql = "SELECT x.id
                FROM {{$basetable}} x
                WHERE $where
            ORDER BY x.id";

        $limitfrom = 0;
        $limitnum = $settings->batch;
        $counttotal = 0;
        $countsucc = 0;
        $countfail = 0;

        do {
            if (microtime(true) - $starttime >= $settings->runtime) {
                mtrace("Stopping the task, the maximum runtime has been exceeded ({$settings->runtime} seconds).");
                break; // Exit the loop after the specified runtime
            }

            mtrace("Reading at offset {$limitfrom} ...", ' ');
            $records = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
            $count = count($records);
            $counttotal += $count;
            mtrace("read {$count} records.");

            $eventids = array_keys($records);

            if (empty($eventids)) {
                break;
            }

            $mover = new \logstore_xapi\log\moveback($eventids, XAPI_REPORT_ID_ERROR);

            if ($mover->execute()) {
                $countsucc += $count;
                mtrace("$count events successfully sent for reprocessing. Not increasing the offset (records were moved).");
            } else {
                $limitfrom += $count; // Increase the offset, when failed to move.
                $countfail += $count;
                mtrace("$count events failed to send for reprocessing. Increasing the offset by {$count} (records were not moved).");
            }
        } while ($count > 0);

        mtrace("Total of {$counttotal} records matched the scope.");
        mtrace("Total of {$countsucc} events successfully sent for reprocessing.");
        mtrace("Total of {$countfail} events failed to send for reprocessing.");
    }
}
