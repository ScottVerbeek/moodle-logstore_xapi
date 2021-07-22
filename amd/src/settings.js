// This file is part of Moodle - http://moodle.org///
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
 * Settings.
 * @module     logstore_xapi/settings
 * @package    logstore_xapi
 * @copyright  2021 Heena Agheda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 define(['jquery'], function($) {
     /** @alias module:logstore_xapi/settings */
    return {
        init: function() {
            // Initial check.
            if($('#id_s_logstore_xapi_mbox').is(':checked')) {
                $('#id_s_logstore_xapi_hashmbox').removeAttr('disabled');
            } else {
                $('#id_s_logstore_xapi_hashmbox').attr('disabled','disabled');
            }

            // OnChange check.
            $('#id_s_logstore_xapi_mbox').change(function() {
                if(this.checked) {
                    $('#id_s_logstore_xapi_hashmbox').removeAttr('disabled');
                } else {
                    $('#id_s_logstore_xapi_hashmbox').attr('disabled','disabled');
                }
            });
        }
    };
});
