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
class resendadhoc_task extends \core\task\adhoc_task {

    public static function instance(
        string $errortype,
        string $eventname,
        int $datefrom,
        int $dateto,
        int $runtime,
        int $batch
    ): self {
        $task = new self();
        $task->set_custom_data((object) [
            'errortype' => $errortype,
            'eventname' => $eventname,
            'datefrom' => $datefrom,
            'dateto' => $dateto,
            'runtime' => $runtime,
            'batch' => $batch
        ]);

        return $task;
    }

    /**
     * Do the job.
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {
        global $DB;

        $options = $this->get_custom_data();

        $basetable = XAPI_REPORT_SOURCE_FAILED;
        $params = [];
        $where = [];

        // Start of sanitization, and applying of scope.
        mtrace("Program will stop after {$options->runtime} seconds have passed, started at " .date('Y-m-d H:i:s T') . "...");
        $starttime = microtime(true);

        mtrace("Program will run in batches of {$options->batch} records ...");

        if (!empty($options->errortype)) {
            $options->errortype = explode(',', $options->errortype);
            $options->errortype = array_map('intval', $options->errortype);
            list($insql, $inparams) = $DB->get_in_or_equal($options->errortype, SQL_PARAMS_NAMED, 'errt');
            $where[] = "x.errortype $insql";
            $params = array_merge($params, $inparams);
            mtrace('Applied scope for errortype ...');
        }

        if (!empty($options->eventname)) {
            $options->eventname = explode(',', $options->eventname);
            list($insql, $inparams) = $DB->get_in_or_equal($options->eventname, SQL_PARAMS_NAMED, 'evt');
            $where[] = "x.eventname $insql";
            $params = array_merge($params, $inparams);
            mtrace('Applied scope for eventname ...');
        }

        if (!empty($options->datefrom) && $options->datefrom !== false) {
            $where[] = 'x.timecreated >= :datefrom';
            $params['datefrom'] = $options->datefrom;
            mtrace('Applied scope for datefrom ...');
        }

        if (!empty($options->dateto) && $options->dateto !== false) {
            $where[] = 'x.timecreated <= :dateto';
            $params['dateto'] = $options->dateto;
            mtrace('Applied scope for dateto ...');
        }

        if (!empty($options->datefrom) && !empty($options->dateto)) {
            if ($options->datefrom > $options->dateto) {
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
        $limitnum = $options->batch;
        $counttotal = 0;
        $countsucc = 0;
        $countfail = 0;

        do {
            if (microtime(true) - $starttime >= $options->runtime) {
                mtrace("Stopping the program, the maximum runtime has been exceeded ({$options->runtime} seconds).");
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
