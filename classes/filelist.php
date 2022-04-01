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
 * Open Educational Resources Plugin
 *
 * @package    local_oer
 * @author     Christian Ortner <christian.ortner@tugraz.at>
 * @copyright  2017-2022 Educational Technologies, Graz, University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oer;

use local_oer\helper\license;

/**
 * Class filelist
 */
class filelist {
    /**
     * @var array
     */
    private $coursefiles = null;

    /**
     * Constructor
     *
     * @param int $courseid
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function __construct($courseid) {
        $mod         = get_fast_modinfo($courseid);
        $coursefiles = [];

        $fs = get_file_storage();

        foreach ($mod->cms as $cm) {
            $skip = false;
            switch ($cm->modname) {
                case 'folder':
                    $component = 'mod_folder';
                    $area      = 'content';
                    break;
                case 'resource':
                    $component = 'mod_resource';
                    $area      = 'content';
                    break;
                default:
                    $component = '';
                    $area      = '';
                    $skip      = true;
            }
            if ($skip) {
                continue;
            }
            $files = $fs->get_area_files($cm->context->id, $component, $area, false,
                                         'sortorder DESC', false);

            foreach ($files as $file) {
                $coursefiles[] = [
                        'file'   => $file,
                        'module' => $cm,
                ];
            }
        }

        $this->coursefiles = $coursefiles;
    }

    /**
     * Get course files
     *
     * @return array
     */
    public function get_files() {
        return $this->coursefiles;
    }

    /**
     * Load all files from moodle course modules and return all files and modules.
     *
     * At the moment only mod_folder and mod_resource are supported.
     * Most of the other modules will probably work out of the box when added, but this has not been tested.
     * For future updates this part should be extracted to subplugins. So it would be easier to also support 3rd party plugins.
     *
     * @param int $courseid Moodle courseid
     * @return array
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public static function get_course_files(int $courseid): array {
        $mod         = get_fast_modinfo($courseid);
        $coursefiles = [];

        $fs = get_file_storage();

        // TODO: separate the file loader into subplugins.
        foreach ($mod->cms as $cm) {
            $skip = false;
            switch ($cm->modname) {
                case 'folder':
                    $component = 'mod_folder';
                    $area      = 'content';
                    break;
                case 'resource':
                    $component = 'mod_resource';
                    $area      = 'content';
                    break;
                default:
                    $component = '';
                    $area      = '';
                    $skip      = true;
            }
            if ($skip) {
                continue;
            }
            $files = $fs->get_area_files($cm->context->id, $component, $area, false,
                                         'sortorder DESC', false);

            foreach ($files as $file) {
                $coursefiles[$file->get_contenthash()][] = [
                        'file'   => $file,
                        'module' => $cm,
                ];
            }
        }

        return $coursefiles;
    }

    /**
     * Load a single file and the module it is added.
     *
     * @param int    $courseid    Moodle courseid
     * @param string $contenthash File contenthash
     * @return array|null
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public static function get_single_file(int $courseid, string $contenthash): ?array {
        $files = self::get_course_files($courseid);
        return $files[$contenthash] ?? null;
    }

    /**
     * Loads a list of all files and their metadata for the frontend.
     *
     * @param int    $courseid    Moodle courseid
     * @param string $contenthash File contenthash (optional if only one file should be loaded)
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_simple_filelist(int $courseid, string $contenthash = ''): array {
        global $DB;
        list($icons, $typegroup, $renderer) = self::prepare_file_icon_renderer($courseid);
        $files             = self::get_course_files($courseid);
        $list              = [];
        $sections          = [];
        $nothumbnail       = count($files) > 20;

        foreach ($files as $file) {
            if (!empty($contenthash) && $file[0]['file']->get_contenthash() != $contenthash) {
                continue;
            }
            $filesections = [];
            $modules      = [];

            foreach ($file as $key => $duplicate) {
                $section                          = [
                        'sectionnum'  => $duplicate['module']->sectionnum,
                        'sectionname' => get_section_name($courseid, $duplicate['module']->sectionnum)
                ];
                $filesections[]                   = $section;
                $sections[$section['sectionnum']] = $section;
                $modules[]                        = [
                        'moduleurl'  => !is_null($duplicate['module']->url) ? $duplicate['module']->url->out() : '#',
                        'modulename' => $duplicate['module']->name ?? 'Module not found',
                ];
            }
            list($icon, $icontype, $iconisimage) = self::select_file_icon_or_thumbnail($file[0]['file'], $renderer, $icons,
                                                                                       $typegroup, $nothumbnail);
            $preference = $DB->get_record('local_oer_preference', ['courseid' => $courseid]);
            $entry      = [
                    'id'             => 0, // Record does not exist yet.
                    'contenthash'    => $file[0]['file']->get_contenthash(),
                    'title'          => $file[0]['file']->get_filename(),
                    'mimetype'       => $file[0]['file']->get_mimetype(),
                    'icon'           => $icon,
                    'icontype'       => $icontype,
                    'iconisimage'    => $iconisimage,
                    'timemodified'   => '-',
                    'timeuploaded'   => '-',
                    'timeuploadedts' => 0,
                    'upload'         => 0,
                    'ignore'         => $preference && $preference->state == 2 ? 1 : 0,
                    'deleted'        => 0,
                    'modules'        => $modules,
                    'sections'       => $filesections,
                    'licensecorrect' => false,
                    'license'        => 'Licence not specified',
                    'personmissing'  => true,
                    'contextset'     => false,
            ];
            // First, test if a file entry exist. Overwrite basic fields with file entries.
            if ($DB->record_exists('local_oer_files',
                                   ['courseid' => $courseid, 'contenthash' => $file[0]['file']->get_contenthash()])) {
                $record                  = $DB->get_record('local_oer_files',
                                                           ['courseid'    => $courseid,
                                                            'contenthash' => $file[0]['file']->get_contenthash()]);
                $snapshotsql             = "SELECT MAX(timecreated) as 'release' FROM {local_oer_snapshot} WHERE "
                                           . "courseid = :courseid AND contenthash = :contenthash";
                $snapshot                = $DB->get_record_sql($snapshotsql,
                                                               ['courseid'    => $courseid,
                                                                'contenthash' => $file[0]['file']->get_contenthash()]);
                $entry['id']             = $record->id;
                $entry['title']          = $record->title;
                $entry['timemodified']   = $record->timemodified > 0 ? userdate($record->timemodified) : '-';
                $entry['timeuploaded']   = !is_null($snapshot->release) ? userdate($snapshot->release) : '-';
                $entry['timeuploadedts'] = $snapshot->release;
                $entry['upload']         = $record->state == 1 ? 1 : 0;
                $entry['ignore']         = $record->state == 2 ? 1 : 0;
                $entry['licensecorrect'] = license::test_license_correct_for_upload($record->license);
                $entry['license']        = license::get_license_fullname($record->license);
                $persons = json_decode($record->persons);
                $entry['personmissing']  = empty($persons->persons);
                $entry['contextset']     = $record->context > 0;
            }
            if (!empty($contenthash) && $file[0]['file']->get_contenthash() == $contenthash) {
                return $entry;
            }
            $list[] = $entry;
        }
        // TODO - orphaned metadata is missing and has to be added..
        return [$list, $sections];
    }

    /**
     * Load a single file and the metadata of it for frontend.
     * This is a wrapper that calls get_simple_filelist with the optional contenthash parameter.
     *
     * @param int    $courseid    Moodle courseid
     * @param string $contenthash File contenthash
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_simple_file(int $courseid, string $contenthash) {
        return self::get_simple_filelist($courseid, $contenthash);
    }

    /**
     * Find the correct file icon.
     * This method uses the icons the moodle filetype page has defined.
     *
     * @param int $courseid Moodle courseid
     * @return array
     */
    private static function prepare_file_icon_renderer(int $courseid): array {
        global $CFG, $PAGE;
        require_once($CFG->libdir . '/filelib.php');
        $context = \context_course::instance($courseid);
        $PAGE->set_context($context);
        $types     = \get_mimetypes_array();
        $icons     = [];
        $typegroup = [];
        foreach ($types as $type) {
            if (isset($type['icon'])) {
                $icons[$type['type']] = $type['icon'];
            }
            if (isset($type['string'])) {
                $typegroup[$type['type']] = $type['string'];
            }
        }
        $renderer = new \core_renderer($PAGE, 'course');
        return [$icons, $typegroup, $renderer];
    }

    /**
     * When the file is an image, a thumbnail is created.
     * When the file is not an image, the file icon is loaded instead.
     *
     * @param \stored_file   $file
     * @param \core_renderer $renderer
     * @param array          $icons
     * @param array          $typegroup
     * @param bool           $nothumbnail
     * @return array
     */
    private static function select_file_icon_or_thumbnail(\stored_file $file, \core_renderer $renderer, array $icons,
                                                          array        $typegroup, bool $nothumbnail) {
        $mimetype = $file->get_mimetype();
        if ($typegroup[$mimetype] == 'image' && !$nothumbnail) {
            $icon        = $file->generate_image_thumbnail(60, 60);
            $icontype    = strpos($icon, "�PNG\r\n") === 0 ? 'png' : 'jpeg';
            $iconisimage = true;
            $icon        = base64_encode($icon);
        } else {
            $fullicon    = $renderer->pix_icon('f/' . $icons[$mimetype], '');
            $icontype    = 'icon';
            $iconisimage = false;
            $iconpart    = explode('src="', $fullicon);
            $iconurl     = explode('"', $iconpart[1]);
            $icon        = $iconurl[0];
        }
        return [$icon, $icontype, $iconisimage];
    }
}