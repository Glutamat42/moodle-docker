<?php
# requirements
# - curl
# - unzip

define('CLI_SCRIPT', true);

$config_php_path = __DIR__ . '/../config.php';


require_once($config_php_path);

global $CFG, $DB;

require_once($CFG->libdir . "/clilib.php");
require_once($CFG->libdir . "/moodlelib.php");
require_once($CFG->libdir . "/externallib.php");
require_once($CFG->libdir . "/accesslib.php");
require_once("lib.php");


## cli opts
$help = "Command line tool to uninstall plugins.

Options:
    -h --help                       Print this help.
    --first_run                     Set this flag if this script is run the first time
    --plugin_version                Version of AdLer plugins to install. main or exact release name. Defaults to main.
    --user_name                     Plain user that will be created during first_run. This user does not have any special permissions, it will be a default \"student\". This field will be the login name and used as default value for optional fields. name and password parameters are required if this user should be created. This is a comma separated list. To add multiple users use for example --user_name=user1,user,user3. All used switches has to have the same array length. Use false if you want the default behavior (eg --user_first_name=John,false,Peter)
    --user_password                 Passwords are not allowed to contain \",\". Passwords have to follow moodle password validation rules.
    --user_first_name
    --user_last_name
    --user_email
    --user_role                     shortname of one role
    --develop_dont_install_plugins  DEVELOP OPTION: Skip plugin installation
";

list($options, $unrecognised) = cli_get_params([
    'help' => false,
    'first_run' => false,
    'plugin_version' => 'main',
    'user_name' => false,
    'user_password' => false,
    'user_first_name' => false,
    'user_last_name' => false,
    'user_email' => false,
    'user_role' => false,
    'develop_dont_install_plugins' => false,
], []);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error('unknown option(s):' . $unrecognised);
}


if ($options['help']) {
    cli_writeln($help);
    exit(0);
}

# cast boolean cli opts
$options['first_run'] = $options['first_run'] == "true";
$options['develop_dont_install_plugins'] = $options['develop_dont_install_plugins'] == "true";
## end cli opts
cli_writeln('CLI options: ' . json_encode((object) array_merge((array) $options, ['user_password' => '***'])));

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
    $cap->roleid = $role_id = $DB->get_record('role', array('shortname' => 'user'))->id;  # role id of "authenticated user"
    $cap->capability = 'moodle/webservice:createtoken';
    $cap->permission = 1;  // no idea what this is for, but it seems this is always 1
    $cap->timemodified = time();
    $cap->modifierid = 0;
    $DB->insert_record('role_capabilities', $cap);

    // enable rest:use for webservices other than moodle mobile service
    $cap = new stdClass();
    $cap->contextid = 1;  // no idea what this is for, but it seems this is always 1
    $cap->roleid = $role_id = $DB->get_record('role', array('shortname' => 'user'))->id;  # role id of "authenticated user"
    $cap->capability = 'webservice/rest:use';
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

function get_plugin_config() {
    $url = __DIR__ . '/plugin-releases.json';
//    $url = 'https://raw.githubusercontent.com/Glutamat42/moodle-docker/main/plugin-releases.json';
    $file_content = file_get_contents($url);
    return json_decode($file_content, true);
}


if ($options['develop_dont_install_plugins']) {
    cli_writeln("skipping plugin installation");
} else {
    cli_writeln("installing plugins");

    $plugin_release_info = get_plugin_config();

    $plugins = [];
    if (isset($plugin_release_info['common_versions'][$options['plugin_version']])) {
        foreach ($plugin_release_info['common_versions'][$options['plugin_version']] as $plugin) {
            $path = $CFG->dirroot . $plugin['path'];

            if (preg_match('/^[0-9]+(\.[0-9]+){0,2}(-rc(\.[0-9]+)?)?$/', $plugin['version'])) {
                // plugin is a release
                $info = get_updated_release_info(
                    $plugin['git_project'],
                    $plugin['version'],
                    core_plugin_manager::instance()->get_plugin_info($plugin['name'])->release
                );

                if ($info === false) {
                    cli_writeln("No update available for {$plugin['name']} {$plugin['version']}");
                    continue;
                } else if ($info !== null && property_exists($info, 'tag_name')) {
                    // checking for one of the keys is sufficient
                    $url = $info->zip_url;
                } else {
                    cli_error("Failed to get release info for {$plugin['name']} {$plugin['version']}");
                }
            } else {
                // plugin is a branch
                $url = "https://github.com/" . $plugin['git_project'] . "/archive/refs/heads/" . $plugin['version'] . ".zip";
            }

            /** @noinspection PhpUndefinedVariableInspection */
            $plugins[] = [
                "path" => $path,
                "url" => $url
            ];
        }
    } else {
        cli_error("plugin version not found");
    }

    cli_writeln("plugins to install: " . json_encode($plugins));
    foreach ($plugins as $plugin) {
        update_plugin($plugin);
    }
}


// upgrade moodle installation
cli_writeln("Upgrading moodle installation...");
$cmd = "php {$CFG->dirroot}/admin/cli/upgrade.php --non-interactive --allow-unstable";
cli_writeln("Executing: $cmd");
exec($cmd, $blub, $result_code);
if ($result_code != 0) {
    cli_error('command execution failed');
}
