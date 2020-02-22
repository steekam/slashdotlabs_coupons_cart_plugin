<?php


namespace ConsoleCommands\Helpers;


use ConsoleCommands\PublishCommand;
use Exception;

class InfoUpdaterHelper
{

    private $io;
    private $git_helper;

    /**
     * InfoUpdaterHelper constructor.
     * @param PublishCommand $command
     */
    public function __construct(PublishCommand $command)
    {
        $this->io = $$command->io;
        $this->git_helper = $command->git_helper;

    }

    /**
     * @param string $new_version
     * @throws Exception
     */
    public function upate_plugin_info(string $new_version)
    {
        $config = console_app_config();
        $plugin_file = dirname(__FILE__, 3) . DIRECTORY_SEPARATOR . $config['plugin_file'];
        if (!file_exists($plugin_file)) throw new Exception($plugin_file . " not found ");
        $regex_pattern = "/(?<=(\*\ Version:))(\s+)(.*)/m";
        $plugin_file_contents = file_get_contents($plugin_file);
        $plugin_file_contents = preg_replace($regex_pattern, '\\2 ' . $new_version, $plugin_file_contents);
        file_put_contents($plugin_file, $plugin_file_contents);
        $this->io->text("<info>Updated version in plugin file</info>");

        // Commit change to file
        $this->git_helper->commit_file($plugin_file, "chore: Bumped up plugin version in file");
    }

    public function update_info_json($release_info)
    {
        // TODO:
    }

    public function partial_changelog_update(string $new_version)
    {
        /**
         * For now you are prompted to update the changelog without out any automated
         * changes.
         */

    }

}