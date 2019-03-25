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
 * @package    local_bl_course_restore
 * @copyright  2017 Sudhanshu Gupta (Sudhanshug5@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->libdir . "/externallib.php");
require_once($CFG->dirroot . '/lib/moodlelib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

class local_remote_backup_restore_course extends external_api {

  public static function create_backup_restore_parameters() {
    return new external_function_parameters(
        array(
      'id' => new external_value(PARAM_INT, 'id'),
      'userid' => new external_value(PARAM_INT, 'userid'),
      'fullname' => new external_value(PARAM_TEXT, 'fullname'),
      'shortname' => new external_value(PARAM_TEXT, 'shortname'),
      'timestart' => new external_value(PARAM_INT, 'timestart'),
      'primaryinstructor' => new external_value(PARAM_INT, 'primaryinstructor'),
      'secondaryinstructor' => new external_value(PARAM_INT, 'secondaryinstructor'),
      'courseinstanceid' => new external_value(PARAM_TEXT, 'courseinstanceid'),
        )
    );
  }

  public static function create_backup_restore($id, $userid, $fullname, $shortname, $timestart, $primaryinstructor, $secondaryinstructor, $courseinstanceid) {

    global $CFG, $DB;

    // Validate parameters passed from web service.
    $params = self::validate_parameters(
            self::create_backup_restore_parameters(), array('id' => $id, 'userid' => $userid, 'fullname' => $fullname, 'shortname' => $shortname
          , 'timestart' => $timestart, 'primaryinstructor' => $primaryinstructor, 'secondaryinstructor' => $secondaryinstructor, 'courseinstanceid' => $courseinstanceid)
    );
    if ($courseinstanceid == 0) {
      $categoryid = $DB->get_field('course_categories', 'id', array('name' => 'BL Course Instances'));
      ;
// Instantiate controller.
      $bc = new backup_controller(
          \backup::TYPE_1COURSE, $id, backup::FORMAT_MOODLE, backup::INTERACTIVE_YES, backup::MODE_GENERAL, $userid);

// Run the backup.
      $bc->set_status(backup::STATUS_AWAITING);
      $bc->execute_plan();
      $result = $bc->get_results();

//restore code Sudhanshu Gupta
      require_once($CFG->dirroot . '/lib/formslib.php');
      require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');


// restore_dbops::create_new_course($fullname, $shortname, $categoryid);
// Restore backup into course
      if (isset($result['backup_destination']) && $result['backup_destination']) {
        $file = $result['backup_destination'];
        $context = context_course::instance($id);
        $fs = get_file_storage();
        $timestamp = time();

        $filerecord = array(
          'contextid' => $context->id,
          'component' => 'local_remote_backup_provider',
          'filearea' => 'backup',
          'itemid' => $timestamp,
          'filepath' => '/',
          'filename' => 'foo',
          'timecreated' => $timestamp,
          'timemodified' => $timestamp
        );

        $storedfile = $fs->create_file_from_storedfile($filerecord, $file);
        $myfile = $fs->get_file_by_hash($storedfile->get_pathnamehash());
        $filepath = md5(time() . '-' . $context->id . '-' . $userid . '-' . random_string(20));

        $fb = get_file_packer('application/vnd.moodle.backup');

        $outcome = $fb->extract_to_pathname($storedfile, $CFG->tempdir . '/backup/' . $filepath . '/', null, null);
      }

// Create new course
      $category = $DB->get_record('course_categories', array('id' => $categoryid), '*', MUST_EXIST);

      $course = new stdClass;
      $course->fullname = $fullname;
      $course->shortname = $shortname;
      $course->category = $category->id;
      $course->sortorder = 0;
      $course->timecreated = $timestart;
      $course->timemodified = $course->timecreated;
      // forcing skeleton courses to be hidden instead of going by $category->visible , until MDL-27790 is resolved.
      $course->visible = 0;

      $courseid = $DB->insert_record('course', $course);

      $category->coursecount++;
      $DB->update_record('course_categories', $category);

      $controller = new restore_controller($filepath, $courseid, backup::INTERACTIVE_YES, backup::MODE_GENERAL, $userid, backup::TARGET_NEW_COURSE);
      $controller->set_status(backup::STATUS_AWAITING);
      $finalcourseid = $controller->get_courseid();
      $plan = $controller->get_plan();
      $tasks = $plan->get_tasks();
      foreach ($tasks as &$task) {
        // We are only interested in schema settings.
        if (!($task instanceof restore_root_task)) {
          // Store as a variable so we can iterate by reference.
          $settings = $task->get_settings();
          // Iterate by reference.
          foreach ($settings as &$setting) {
            $name = $setting->get_ui_name();
            if ($name == 'setting_course_course_fullname') {
              $setting->set_value($fullname);
            }
            else if ($name == 'setting_course_course_shortname') {
              $setting->set_value($shortname);
            }
            else if ($name == 'setting_course_course_startdate') {
              $setting->set_value($timestart);
            }
            else if ($name == 'setting_root_users' || $name == 'setting_root_role_assignments' || $name == 'setting_root_userscompletion' || $name == 'setting_root_badges' || $name == 'setting_root_comments') {
              $setting->set_value(0);
            }
          }
        }
      }


      $controller->execute_plan();
      $result = $controller->get_results();
      global $CFG, $DB;
      require_once($CFG->libdir . '/enrollib.php');
      $roleid = $DB->get_field('role', 'id', array('shortname' => 'courseinstructor'));
      if ($primaryinstructor != 0) {
        enrol_try_internal_enrol($finalcourseid, $primaryinstructor, $roleid);
      } if ($secondaryinstructor != 0) {
        enrol_try_internal_enrol($finalcourseid, $secondaryinstructor, $roleid);
      }

      //update the course to ommit the copy1 in case of already exist name
      $data->id = $finalcourseid;
      $data->fullname = $fullname;
      $DB->update_record('course', $data);
    } //clone action end
    else {
      $finalcourseid = $courseinstanceid;
      $context = context_course::instance($finalcourseid, MUST_EXIST);

//update the course parameter
      $data = new stdClass();
      $data->id = $courseinstanceid;
      $data->fullname = $fullname;
      $data->shortname = $shortname;
      $data->startdate = $timestart;
      $DB->update_record('course', $data);

      //unenrol the users
      $roleid = $DB->get_field('role', 'id', array('shortname' => 'courseinstructor'));
      $enrolid = $DB->get_field('enrol', 'id', array('enrol' => 'manual', 'courseid' => $finalcourseid));
      $enrolled_user = get_enrolled_users($context, null, null, 'u.id');
      $courseinstructorid = array();
      foreach ($enrolled_user as &$userid) {
        if (user_has_role_assignment($userid->id, $roleid)) { //9 is the roleid of course instructor
          $courseinstructorid[] = $userid->id;
        }
      }
      foreach ($courseinstructorid as &$instructorid) {
        $DB->delete_records('user_enrolments', array('enrolid' => $enrolid, 'userid' => $instructorid));
        $DB->delete_records('role_assignments', array('contextid' => $context->id, 'roleid' => $roleid, 'userid' => $instructorid));
      }

      //enrol the new users
      if ($primaryinstructor != 0) {
        enrol_try_internal_enrol($finalcourseid, $primaryinstructor, $roleid);
      } if ($secondaryinstructor != 0) {
        enrol_try_internal_enrol($finalcourseid, $secondaryinstructor, $roleid);
      }
    }
    return array('courseid' => $finalcourseid);
  }

  public static function create_backup_restore_returns() {
    return new external_single_structure(
        array(
      'courseid' => new external_value(PARAM_INT, 'courseid'),
        )
    );
  }

}
