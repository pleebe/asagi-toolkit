<?php
if (!file_exists('vendor/autoload.php')) {
    echo "Run composer install first\n";
    die();
}
require 'vendor/autoload.php';
if (!file_exists('config.json')) {
    echo "Fill in your database server details in config.json\n";
    die();
}
$config = json_decode(file_get_contents('config.json'));

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

function processText($text)
{
    $text = preg_replace('/<br\s?\/?>/' , "\n", $text);
    $text = preg_replace('/&gt;/' , '>', $text);
    $text = preg_replace('/&lt;/' , '<', $text);
    $text = preg_replace('/&amp;/' , '&', $text);
    $text = preg_replace('/<span class="abbr">Comment too long. Click<a href=".*?">here<\/a>to view the full text.<\/span>/', '', $text);
    $text = preg_replace('/<span class="spoiler".*?>(.*?)<\/span>/', '[spoiler]$1[/spoiler]', $text);
    $text = preg_replace('/<pre.*?>(.*?)<\/pre>/', '[code]$1[/code]', $text);
    $text = preg_replace('/<(?:b|strong) style="color: red;">(.*?)<\/(?:b|strong)>/', '[banned]$1[/banned]', $text);
    $text = preg_replace('/<span class="fortune" style="color:(.*?)">(.*?)<\/span>/', '[fortune color="$1"]$2[/fortune]', $text);
    return $text;
}

function insertPost($db, $board, $post, $thread_num, $op)
{
    $db->createQueryBuilder()
        ->insert($board)
        ->values([
            'num' => ':num',
            'subnum' => ':subnum',
            'thread_num' => ':thread_num',
            'op' => ':op',
            'timestamp' => ':timestamp',
            'email' => ':email',
            'name' => ':name',
            'trip' => ':trip',
            'title' => ':title',
            'comment' => ':comment',
        ])
        ->setParameter(':num', (string)$post->attributes()->num)
        ->setParameter(':subnum', 0)
        ->setParameter(':thread_num', $thread_num)
        ->setParameter(':op', $op)
        ->setParameter(':timestamp', (int)strtotime((string)$post->attributes()->date))
        ->setParameter(':email', ($post->email ? $post->email : null))
        ->setParameter(':name', ($post->poster ? $post->poster : 'Anonymous'))
        ->setParameter(':trip', ($post->tripcode ? $post->tripcode : null))
        ->setParameter(':title', ($post->subject ? processText($post->subject) : null))
        ->setParameter(':comment', processText($post->text))
        ->execute();
}

$console = new Application();
$console->register('import')
    ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Location of downloaded XML files')
    ->addOption('destination', 'd', InputOption::VALUE_REQUIRED, 'Destination database')
    ->addOption('board', 'b', InputOption::VALUE_REQUIRED, 'Board you want to import')
    ->addOption('chunk', 'c', InputOption::VALUE_OPTIONAL, 'Chunk of posts', 1000)
    ->addOption('sleep', 'l', InputOption::VALUE_OPTIONAL, 'Sleep time in seconds after chunk', 10)
    ->setCode(function(InputInterface $input, OutputInterface $output) use ($config) {
        if (!file_exists($input->getOption('source')) || !is_dir($input->getOption('source'))) {
            $output->writeln('Source XML directory ' . $input->getOption('source') . ' does not exist or it is not a directory');
            die();
        }

        try {
            $db = \Doctrine\DBAL\DriverManager::getConnection([
                'dbname' => $input->getOption('destination'),
                'user' => $config->user,
                'password' => $config->password,
                'host' => $config->host,
                'driver' => $config->driver
            ], new \Doctrine\DBAL\Configuration());
            if (!$db->getSchemaManager()->tablesExist($input->getOption('board'))) {
                $output->writeln('Board table ' . $input->getOption('destination') . '.' . $input->getOption('board') . ' does not exist');
                die();
            }
        } catch (ConnectionException $e) {
            $output->writeln($e->getMessage());
            die();
        }

        $count = 0;

        foreach (new DirectoryIterator($input->getOption('source')) as $file) {
            if (!$file->isDot() && !$file->isDir() && $file->getExtension() == 'xml') {
                $output->writeln('* Processing file ' . $file->getRealPath() . ' into table ' . $input->getOption('destination') . '.' . $input->getOption('board'));
                $thread = simplexml_load_file($file->getRealPath(), 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($thread === false) {
                    $output->writeln('   XML error in file ' . $file->getRealPath());
                    foreach (libxml_get_errors() as $error) {
                        $output->writeln($error->message);
                    }
                } else {
                    if (is_object($thread->post)) {
                        // $output->writeln('  No.'.(string)$thread->post->attributes()->num); // position

                        try {
                            insertPost($db, $input->getOption('board'), $thread->post, (string)$thread->post->attributes()->num, 1);
                        } catch (UniqueConstraintViolationException $e) {
                            $output->writeln('   Post No.' . (string)$thread->post->attributes()->num . ' already exists. Continuing...');
                        } catch (DBALException $e) {
                            $output->writeln($e->getMessage());
                        }

                        $count++;
                        // sleep after chunk
                        if ($count % $input->getOption('chunk') == 0) {
                            $output->writeln('* Sleeping');
                            sleep($input->getOption('sleep'));
                        }

                        if (is_object($thread->post->replies)) {
                            foreach ($thread->post->replies->post as $reply) {
                                // $output->writeln('  No.'.(string)$reply->attributes()->num); // position
                                try {
                                    insertPost($db, $input->getOption('board'), $reply, (string)$thread->post->attributes()->num, 0);
                                } catch (UniqueConstraintViolationException $e) {
                                    $output->writeln('   Post No.' . (string)$reply->attributes()->num . ' already exists. Continuing...');
                                } catch (DBALException $e) {
                                    $output->writeln($e->getMessage());
                                }

                                $count++;
                                // sleep after chunk
                                if ($count % $input->getOption('chunk') == 0) {
                                    $output->writeln('* Sleeping');
                                    sleep($input->getOption('sleep'));
                                }
                            }
                        }
                    }
                }
            }
        }
    })
    ->getApplication()
    ->setDefaultCommand('import', true)
    ->run();