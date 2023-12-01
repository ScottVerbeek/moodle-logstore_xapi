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

        $errortype = (string) get_config('logstore_xapi', 'resendtaskerrortype');
        $eventname = (string) get_config('logstore_xapi', 'resendeventname');
        $datefrom = (int) get_config('logstore_xapi', 'resenddatefrom');
        $dateto = (int) get_config('logstore_xapi', 'resenddateto');
        $runtime = (int) get_config('logstore_xapi', 'resendtaskruntime');
        $batch = (int) get_config('logstore_xapi', 'resendbatch');

        $task = \logstore_xapi\task\resendadhoc_task::instance($errortype, $eventname, $datefrom, $dateto, $runtime, $batch);
        \core\task\manager::queue_adhoc_task($task);

        mtrace("Queued a new adhoc task \logstore_xapi\task\resendadhoc_task with configuration ... " . $task->get_custom_data_as_string());
    }
}
