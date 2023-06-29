<?php
# requirements
# - curl
# - unzip

# TODO
# cleanup / multiple moodle <-> plugin combinations
# update reamde: new user

define('CLI_SCRIPT', true);

$config_php_path = __DIR__ . '/config.php';

global $CFG, $DB;

require_once($config_php_path);
require_once($CFG->libdir . "/clilib.php");
require_once($CFG->libdir . "/moodlelib.php");
require_once($CFG->libdir . "/externallib.php");
require_once($CFG->libdir . "/accesslib.php");

use \core\event\user_created;

## define dummy function for syntax highlighting
// @formatter:off
if (!function_exists('cli_writeln')) {
    define('MOODLE_OFFICIAL_MOBILE_SERVICE', 'moodle_mobile_app');
    function cli_writeln($text, $stream = STDOUT) {}
    function cli_error($text, $errorcode = 1) {}
    function cli_get_params(array $longoptions, array $shortmapping = null): object {return (object)[];}
    function set_config($name, $value, $plugin = null) {}
    function get_config($plugin, $name = null): mixed {return '';}
    function user_create_user($user, $updatepassword = true, $triggerevent = true): int {return 1;}
    function profile_save_data(stdClass $usernew): void {}
    function get_complete_user_data($field, $value, $mnethostid = null, $throwexception = false): mixed {return '';}
    function role_assign($roleid, $userid, $contextid, $component = '', $itemid = 0, $timemodified = '') {}
}
// @formatter:on


## cli opts
$help = "Command line tool to uninstall plugins.

Options:
    -h --help           Print this help.
    --first_run         Set this flag if this script is run the first time
    --user_name         Plain user that will be created during first_run. This user does not have any special permissions, it will be a default \"student\". This field will be the login name and used as default value for optional fields. name and password parameters are required if this user should be created. This is a comma separated list. To add multiple users use for example --user_name=user1,user,user3. All used switches has to have the same array length. Use false if you want the default behavior (eg --user_first_name=John,false,Peter)
    --user_password     Passwords are not allowed to contain \",\"
    --user_first_name
    --user_last_name
    --user_email
    --user_role         shortname of one role
";

list($options, $unrecognised) = cli_get_params([
    'help' => false,
    'first_run' => false,
    'user_name' => false,
    'user_password' => false,
    'user_first_name' => false,
    'user_last_name' => false,
    'user_email' => false,
    'user_role' => false,
], []);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error('unknown option(s):' . $unrecognised);
}

if ($options['help']) {
    cli_writeln($help);
    exit(0);
}
## end cli opts

// add user
// originally taken from moodlelib ~4.2, but not much left of it
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
function create_one_user($username, $password, $first_name, $last_name, $email, $role) {
    $newuser = new stdClass();
    // Just in case check text case.
    $newuser->username = trim(strtolower($username));
    $newuser->password = $password;
    $newuser->email = $email;
    $newuser->firstname = $first_name;
    $newuser->lastname = $last_name;
    $newuser->auth = 'manual';
    $newuser->lang = 'en';
    $newuser->timecreated = time();
    $newuser->timemodified = $newuser->timecreated;
    $newuser->confirmed = 1;
    $newuser->mnethostid = get_config('core', 'mnet_localhost_id');
    $newuser->description = "Created during setup";

    $newuser->id = user_create_user($newuser, true, false);
//    Setting the password this way ignore password validation rules
//    update_internal_user_password($user, $password);

    // Save user profile data.
    profile_save_data($newuser);

    $user = get_complete_user_data('id', $newuser->id);

    // Trigger event.
    user_created::create_from_userid($newuser->id)->trigger();

    // assign user to role
    if ($role) {
        global $DB;
        $role_id = $DB->get_record('role', array('shortname' => $role))->id;
        role_assign($role_id, $user->id, 1);
    }

    return $user;
}

function create_users($options) {
    // parse data, set default values
    $users_data = [
        'name' => explode(',', $options['user_name']),
        'password' => explode(',', $options['user_password']),
    ];
    $users_data['first_name'] = $options['user_first_name'] ? explode(',', $options['user_first_name']) : array_fill(0, count($users_data['name']), "false");
    $users_data['last_name'] = $options['user_last_name'] ? explode(',', $options['user_last_name']) : array_fill(0, count($users_data['name']), "false");
    $users_data['email'] = $options['user_email'] ? explode(',', $options['user_email']) : array_fill(0, count($users_data['name']), "false");
    $users_data['role'] = $options['user_role'] ? explode(',', $options['user_role']) : array_fill(0, count($users_data['name']), "false");

    // validation
    foreach (array_keys($users_data) as $key) {
        assert(count($users_data['name']) == count($users_data[$key]), 'all user property arrays has to have the same length');
    }


    for ($i = 0; $i < count($users_data['name']); $i++) {
        $first_name = $users_data['first_name'][$i] != "false" ? $users_data['first_name'][$i] : $users_data['name'][$i];
        $last_name = $users_data['last_name'][$i] != "false" ? $users_data['last_name'][$i] : $users_data['name'][$i];
        $role = $users_data['role'][$i] == "false" ? false : $users_data['role'][$i];
        $email = $users_data['email'][$i] != "false" ?: $users_data['name'][$i] . '@example.example';

        create_one_user($users_data['name'][$i], $users_data['password'][$i], $first_name, $last_name, $email, $role);
    }
}


if ($options['first_run']) {
    // enable webservices
    set_config('enablewebservices', true);

    // enable moodle mobile web service
//    set_config('enablemobilewebservice', true);  // for any reason this does not set the corresponding option in the web ui and everything seems to work without it anyway.
    $external_service_record = $DB->get_record('external_services', array('shortname' => MOODLE_OFFICIAL_MOBILE_SERVICE));
    $external_service_record->enabled = 1;
    $DB->update_record('external_services', $external_service_record);

    // Enable REST server.
    $activeprotocols = empty($CFG->webserviceprotocols) ? array() : explode(',', $CFG->webserviceprotocols);

    if (!in_array('rest', $activeprotocols)) {
        $activeprotocols[] = 'rest';
        $updateprotocol = true;
    }

    if ($updateprotocol) {
        set_config('webserviceprotocols', implode(',', $activeprotocols));
    }

    // enable login for webservices other than moodle mobile service
    $cap = new stdClass();
    $cap->contextid = 1;  // no idea what this is for, but it seems this is always 1
    $cap->roleid = 7;  // TODO get this somehow
    $cap->capability = 'moodle/webservice:createtoken';
    $cap->permission = 1;  // no idea what this is for, but it seems this is always 1
    $cap->timemodified = time();
    $cap->modifierid = 0;
    $DB->insert_record('role_capabilities', $cap);

    // create users
    if ($options['user_name']) {
        cli_writeln("creating user(s)");
        create_users($options);
    }
}


// install plugins
$plugins = [
    [
        "path" => "local/adler",
        "url" => "https://github.com/ProjektAdLer/MoodlePluginLocal/archive/refs/heads/main.zip"
    ],
    [
        "path" => "availability/condition/adler",
        "url" => "https://github.com/ProjektAdLer/MoodlePluginAvailability/archive/refs/heads/main.zip"
    ],
];
foreach ($plugins as $plugin) {
    $plugin_path = $CFG->dirroot . DIRECTORY_SEPARATOR . $plugin["path"];


    if (is_dir($plugin_path)) {
        // delete plugin folder
        cli_writeln("Plugin already installed, updating...");
        $cmd = "rm -rf $plugin_path";
        cli_writeln("Executing: $cmd");
        exec($cmd, $blub, $result_code);
        if ($result_code != 0) {
            cli_error('command execution failed');
        }
    }
    cli_writeln("Installing plugin...");
    $cmd = "mkdir /tmp/plugin && curl -L {$plugin['url']} -o /tmp/plugin/plugin.zip && unzip /tmp/plugin/plugin.zip -d /tmp/plugin/ && rm /tmp/plugin/plugin.zip && mv /tmp/plugin/* $plugin_path && rm -r /tmp/plugin";
    cli_writeln("Executing: $cmd");
    exec($cmd);
}
// upgrade moodle installation
cli_writeln("Upgrading moodle installation...");
$cmd = "php {$CFG->dirroot}/admin/cli/upgrade.php --non-interactive --allow-unstable";
cli_writeln("Executing: $cmd");
exec($cmd, $blub, $result_code);
if ($result_code != 0) {
    cli_error('command execution failed');
}



