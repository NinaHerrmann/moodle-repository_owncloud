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
 * This file contains tests for the repository_owncloud class.
 *
 * @package     repository_owncloud
 * @copyright  2017 Project seminar (Learnweb, University of Münster)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class repository_owncloud_testcase
 * @group repo_owncloud
 * @copyright  2017 Project seminar (Learnweb, University of Münster)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_owncloud_access_controlled_link_testcase extends advanced_testcase {

    /** @var null|\repository_owncloud the repository_owncloud object, which the tests are run on. */
    private $repo = null;

    /** @var null|\core\oauth2\issuer which belongs to the repository_owncloud object.*/
    private $issuer = null;

    /**
     * SetUp to create an repository instance.
     */
    protected function setUp() {
        $this->resetAfterTest(true);

        // Admin is neccessary to create api and issuer objects.
        $this->setAdminUser();

        /** @var repository_owncloud_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('repository_owncloud');
        $this->issuer = $generator->test_create_issuer();

        // Create Endpoints for issuer.
        $generator->test_create_endpoints($this->issuer->get('id'));

        // Params for the config form.
        $reptype = $generator->create_type([
            'visible' => 1,
            'enableuserinstances' => 0,
            'enablecourseinstances' => 0,
            'issuerid' => $this->issuer->get('id'),
            'pluginname' => 'ownCloud',
            'timeintervalsharing' => 604800,
            'controlledlinkfoldername' => 'Moodlefiles',
        ]);

        $instance = $generator->create_instance([
            'issuerid' => $this->issuer->get('id'),
            'timeintervalsharing' => 604800,
            'controlledlinkfoldername' => 'Moodlefiles',
        ]);

        // At last, create a repository_owncloud object from the instance id.
        $this->repo = new repository_owncloud($instance->id);
        $this->repo->options['typeid'] = $reptype->id;
        $this->repo->options['sortorder'] = 1;
        $this->resetAfterTest(true);
    }

    /**
     * The reference_file_selected() methode is called every time a FILE_CONTROLLED_LINK is chosen for upload.
     * Since the function is very long the private function are tested separately, and merely the abortion of the
     * function are tested.
     *
     */
    public function test_reference_file_selected_error() {
        $this->repo->disabled = true;
        $this->expectException(\repository_exception::class);
        $this->repo->reference_file_selected('', context_system::instance(), '', '', '');

        $this->repo->disabled = false;
        $this->expectException(\repository_exception::class);
        $this->expectExceptionMessage('Cannot connect as system user');
        $this->repo->reference_file_selected('', context_system::instance(), '', '', '');

        $mock = $this->createMock(\core\oauth2\client::class);
        $mock->expects($this->once())->method('get_system_oauth_client')->with($this->issuer)->willReturn(true);

        $this->expectException(\repository_exception::class);
        $this->expectExceptionMessage('Cannot connect as current user');
        $this->repo->reference_file_selected('', context_system::instance(), '', '', '');

        $this->repo->expects($this->once())->method('get_user_oauth_client')->willReturn(true);
        $this->expectException(\repository_exception::class);
        $this->expectExceptionMessage('cannotdownload');
        $this->repo->reference_file_selected('', context_system::instance(), '', '', '');


        $this->repo->expects($this->once())->method('get_user_oauth_client')->willReturn(true);
        $this->repo->expects($this->once())->method('create_share_user_sysaccount')->willReturn(array('statuscode' => 100));
        $this->repo->expects($this->once())->method('create_folder_path_access_controlled_links')->willReturn(array('statuscode' => array('success' => 400)));
        $this->expectException(\repository_exception::class);
        $this->expectExceptionMessage('cannotdownload');
        $this->repo->reference_file_selected('', context_system::instance(), '', '', '');

    }

    /**
     * Function to test the private function create_share_user_sysaccount.
     */
    public function test_create_share_user_sysaccount(){
        $dateofexpiration = time() + 604800;
        $username = 'user1';
        $params = [
            'path' => "/ambient.txt",
            'shareType' => \repository_owncloud\ocs_client::SHARE_TYPE_USER,
            'publicUpload' => false,
            'expiration' => $dateofexpiration,
            'shareWith' => $username,
        ];
        $expectedresponse = <<<XML
<?xml version="1.0"?>
<ocs>
 <meta>
  <status>ok</status>
  <statuscode>100</statuscode>
  <message/>
 </meta>
 <data>
  <id>207</id>
  <share_type>0</share_type>
  <uid_owner>user1</uid_owner>
  <displayname_owner>user1</displayname_owner>
  <permissions>19</permissions>
  <stime>1511532198</stime>
  <parent/>
  <expiration/>
  <token/>
  <uid_file_owner>user1</uid_file_owner>
  <displayname_file_owner>user1</displayname_file_owner>
  <path>/ambient.txt</path>
  <item_type>file</item_type>
  <mimetype>text/plain</mimetype>
  <storage_id>home::user1</storage_id>
  <storage>3</storage>
  <item_source>545</item_source>
  <file_source>545</file_source>
  <file_parent>20</file_parent>
  <file_target>/ambient.txt</file_target>
  <share_with>tech</share_with>
  <share_with_displayname>tech</share_with_displayname>
  <mail_send>0</mail_send>
 </data>
</ocs>

XML;
        $mock = $this->getMockBuilder(\repository_owncloud\ocs_client::class)->disableOriginalConstructor()->disableOriginalClone(
        )->getMock();
        $mock->expects($this->once())->method('call')->with('create_share', $params)->will($this->returnValue($expectedresponse));
        $this->set_private_property($mock, 'ocsclient');
        $result = phpunit_util::call_internal_method($this->repo, "create_share_user_sysaccount",
            array('source' => "/ambient.txt", 'username' => "user1", 'temp' => 604800, 'direction' => true), 'repository_owncloud');
        $xml = simplexml_load_string($expectedresponse);

        $expected = array();
        $expected['statuscode'] = $xml->meta->statuscode;
        $expected['shareid'] = $xml->data->id;
        $expected['fileid'] = $xml->data->item_source;
        $expected['filetarget'] = ((string)$xml->data[0]->file_target);

        $this->assertEquals($expected, $result);
    }

    /**
     * Function which test that create folder path does return the adequate results (path and success).
     * Additionally mock checks whether the right params are passed to the corresponding functions.
     */
    public function test_create_folder_path_folders_are_not_created() {
        $mocks = $this->set_up_mocks_for_create_folder_path(true);

        $result = phpunit_util::call_internal_method($this->repo, "create_folder_path_access_controlled_links",
            array($mocks['mockcontext'], 'component' => "mod_resource", 'filearea' => 'content', 'itemid' => 0,
                'sysdav' => $mocks['mockclient']), 'repository_owncloud');
        $expected = array();
        $expected['success'] = true;
        $expected['fullpath'] = '/somename/mod_resource/content/0';
        $this->assertEquals($expected, $result);
    }

    /**
     * Function which test that create folder path does return the adequate results (path and success).
     * Additionally mock checks whether the right params are passed to the corresponding functions.
     */
    public function test_create_folder_path_folders_are_created() {
        $mocks = $this->set_up_mocks_for_create_folder_path(false, true, 201);

        $result = phpunit_util::call_internal_method($this->repo, "create_folder_path_access_controlled_links",
            array($mocks['mockcontext'], 'component' => "mod_resource", 'filearea' => 'content', 'itemid' => 0,
                'sysdav' => $mocks['mockclient']), 'repository_owncloud');
        $expected = array();
        $expected['success'] = true;
        $expected['fullpath'] = '/somename/mod_resource/content/0';
        $this->assertEquals($expected, $result);
    }

    /**
     * Function which test that create folder path does return the adequate results (path and success).
     * Additionally mock checks whether the right params are passed to the corresponding functions.
     */
    public function test_create_folder_path_folder_creation_fails() {
        $mocks = $this->set_up_mocks_for_create_folder_path(false, true, 400);
        $result = phpunit_util::call_internal_method($this->repo, "create_folder_path_access_controlled_links",
            array('context' => $mocks['mockcontext'], 'component' => "mod_resource", 'filearea' => 'content', 'itemid' => 0,
                'sysdav' => $mocks['mockclient']), 'repository_owncloud');
        $expected = array();
        $expected['success'] = false;
        $expected['fullpath'] = '/somename/mod_resource/content/0';
        $this->assertEquals($expected, $result);
    }

    /**
     * Helper function to generate mocks for testing create folder path.
     * @param bool $returnisdir
     * @param bool $callmkcol
     * @param int $returnmkcol
     * @return array
     */
    protected function set_up_mocks_for_create_folder_path($returnisdir, $callmkcol = false, $returnmkcol = 201) {
        $mockcontext = $this->createMock(context_module::class);
        $mocknestedcontext = $this->createMock(context_module::class);
        $mockclient = $this->getMockBuilder(repository_owncloud\owncloud_client::class)->disableOriginalConstructor()
            ->disableOriginalClone()->getMock();

        // Case all folders are already created, therefore mkcol is never called.
        $parsedwebdavurl =parse_url($this->issuer->get_endpoint_url('webdav'));
        $webdavprefix = $parsedwebdavurl['path'];
        $mockclient->expects($this->exactly(4))->method('is_dir')->with($this->logicalOr(
            $this->logicalOr($webdavprefix . '/somename/mod_resource', $webdavprefix . '/somename'),
            $this->logicalOr($webdavprefix . '/somename/mod_resource/content/0', $webdavprefix . '/somename/mod_resource/content')))->willReturn($returnisdir);
        if ($callmkcol == true) {
            $mockclient->expects($this->exactly(4))->method('mkcol')->willReturn($returnmkcol);
        }
        $mockcontext->method('get_parent_contexts')->willReturn(array('1' => $mocknestedcontext));
        $mocknestedcontext->method('get_context_name')->willReturn('somename');
        return array('mockcontext' => $mockcontext, 'mockclient' => $mockclient);
    }
    /**
     * Helper method, which inserts a given mock value into the repository_owncloud object.
     *
     * @param mixed $value mock value that will be inserted.
     * @param string $propertyname name of the private property.
     * @return ReflectionProperty the resulting reflection property.
     */
    protected function set_private_property($value, $propertyname) {
        $refclient = new ReflectionClass($this->repo);
        $private = $refclient->getProperty($propertyname);
        $private->setAccessible(true);
        $private->setValue($this->repo, $value);

        return $private;
    }
}