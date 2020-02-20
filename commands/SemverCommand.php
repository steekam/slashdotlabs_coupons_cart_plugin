<?php

namespace ConsoleCommands;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SemverCommand extends Command
{
    protected static $defaultName = "app:semver";
    private $dry_run;
    private $pre_release;
    private $new_version;
    /**
     * @var SymfonyStyle
     */
    private $io;

    protected function configure()
    {
        $this->setDescription("Create versions based on Semver guidlines");

        $this->register_options();
        $this->register_arguments();
    }

    private function register_options()
    {
        // Dry run
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Get the the next version without persisting changes');

        // Pre-release
        $this->addOption('pre-release', null, InputOption::VALUE_REQUIRED, 'Create a pre-release version');

        // Build
        $this->addOption('build', 'b', InputOption::VALUE_OPTIONAL, 'Add build metadata');
    }

    private function register_arguments()
    {
        // Action to run (get | help | major | minor | patch)
        $this->addArgument('action', InputArgument::REQUIRED, 'Action you want to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        try {
//            if(!$this->is_clean_working_tree()) throw new Exception("Ensure you have a clean working tree first.");

            $this->handle_options($input);

            $action = $input->getArgument('action');
            $valid_actions = ['get', 'major', 'minor', 'patch'];
            if (!in_array($action, $valid_actions)) throw new Exception('Enter a valid action. Run help for more information');
            if ($action === "get") {
                $this->action_get();
            } else {
                $this->update_version($action);
            }
            $this->io->success("Done");
        } catch (Exception $e) {
            $this->io->text($e->getMessage());
            $this->io->error("Execution failed");
        }
        return 0;
    }

    private function handle_options(InputInterface $input)
    {
        $this->dry_run = $input->getOption('dry-run');
        if ($this->dry_run) $this->io->note("In dry run mode, changes won't persist");

        // Handle pre-release
        $this->pre_release = $input->getOption('pre-release');
        if ($this->pre_release) $this->io->note("This is a {$this->pre_release} pre release");
    }

    /**
     * @param string $bump_type
     * @throws Exception
     */
    private function update_version(string $bump_type)
    {
        $this->io->title("Generating next {$bump_type} release");
        $latest = $this->get_latest_version();
        // remove pre-release or build metadata
        $latest = strtok($latest, '-');
        list($major, $minor, $patch) = explode('.', $latest);
        $$bump_type = (int) $$bump_type + 1;
        $new_version = implode(".", [$major, $minor, $patch]);

        if ($this->pre_release) $new_version .= "-" . $this->pre_release;

        if (!($this->dry_run)) {
            $this->update_plugin_info($new_version);
            $this->create_tag($new_version);
        }
        $this->io->text("<info>New version:</info> $new_version");
    }

    private function create_tag(string $new_version)
    {
        $this->io->text("<info>Creating new tag...</info>");
        $process = Process::fromShellCommandline("git tag v{$new_version}");
        $process->run();
        if (!$process->isSuccessful()) throw new ProcessFailedException($process);
        $this->io->text("Created tag: v{$new_version}");

        $this->io->text("<info>Pushing changes upstream...</info>");
        $process = Process::fromShellCommandline("git push origin v{$new_version}");
        $process->run();
        if (!$process->isSuccessful()) throw new ProcessFailedException($process);
        $this->io->text("<info>Tag pushed to origin</info>");
    }

    private function get_latest_version()
    {
        // Run git fetch
        $this->io->text("<info>Updating from origin...</info>");
        $process = Process::fromShellCommandline("git fetch origin");
        $process->run();
        if (!$process->isSuccessful()) throw new ProcessFailedException($process);

        // Get latest tag
        $process = Process::fromShellCommandline("git describe --abbrev=0 --tags");
        $process->run();
        if (!$process->isSuccessful()) throw new ProcessFailedException($process);
        $tag = $process->getOutput();
        return !empty($tag) || $tag !== "" ? ltrim($tag, 'v') : "No versions created";
    }

    private function is_clean_working_tree()
    {
        $process = Process::fromShellCommandline('[[ -n $(git status) ]] || echo clean');
        $process->run();
        if (!$process->isSuccessful()) throw new ProcessFailedException($process);
        return trim($process->getOutput()) === "clean";
    }

    private function action_get()
    {
        // Gets the latest version tag || empty if none
        $this->io->title("Getting you the latest version");
        $latest_version = $this->get_latest_version();
        $this->io->text([
            "<info>Latest Version:</info> {$latest_version}"
        ]);
    }

    /**
     * @param $new_version
     * @throws Exception
     */
    private function update_plugin_info($new_version)
    {
        $config = $this->get_config();
        $plugin_file =  dirname(__FILE__, 2).DIRECTORY_SEPARATOR.$config['plugin_file'];
        if (!file_exists($plugin_file)) throw new Exception($plugin_file." not found ");
        $regex_pattern = "/(?<=(\*\ Version:))(\s+)(.*)/m";
        $plugin_file_contents = file_get_contents($plugin_file);
        $plugin_file_contents = preg_replace($regex_pattern, '\\2 '.$new_version, $plugin_file_contents);
        file_put_contents($plugin_file, $plugin_file_contents);
        $this->io->text("<info>Updated version in plugin file</info>");

        // Commit change
        $this->io->text("<info>Commiting change...</info>");
        $command = "git add {$config['plugin_file']} && git commit -m 'chore: Bumped up plugin version in file'";
        $this->io->comment($command);
        $process = Process::fromShellCommandline($command);
        $process->run();
        if (!$process->isSuccessful()) throw new ProcessFailedException($process);
    }

    /**
     * @throws Exception
     */
    private function get_config()
    {
        $config_file = dirname(__FILE__) .DIRECTORY_SEPARATOR .'config.json';
        if (!file_exists($config_file)) throw new Exception("Missing config.json");
        $config = json_decode(file_get_contents($config_file), true);

        $required_keys = ['github_username', 'github_repo', 'authorize_token', "plugin_file"];
        $intersect = array_intersect($required_keys, array_keys($config));
        if (count($intersect) !== count($required_keys)) throw new Exception("Ensure you have a valid config.json with all required keys ".json_encode($required_keys));
        return $config;
    }
}
