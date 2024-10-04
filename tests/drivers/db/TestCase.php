<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace tests\drivers\db;

use tests\app\PriorityJob;
use tests\app\RetryJob;
use tests\drivers\CliTestCase;
use Yii;
use yii\db\Query;

/**
 * Db Queue Test Case.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
abstract class TestCase extends CliTestCase
{
    public function testRun()
    {
        $job = $this->createSimpleJob();
        $this->getQueue()->push($job);
        $this->runProcess(['php', 'yii', 'queue/run']);

        $this->assertSimpleJobDone($job);
    }

    public function testStatus()
    {
        $job = $this->createSimpleJob();
        $id = $this->getQueue()->push($job);
        $isWaiting = $this->getQueue()->isWaiting($id);
        $this->runProcess(['php', 'yii', 'queue/run']);
        $isDone = $this->getQueue()->isDone($id);

        $this->assertTrue($isWaiting);
        $this->assertTrue($isDone);
    }

    public function testPriority()
    {
        $this->getQueue()->priority(100)->push(new PriorityJob(['number' => 1]));
        $this->getQueue()->priority(300)->push(new PriorityJob(['number' => 5]));
        $this->getQueue()->priority(200)->push(new PriorityJob(['number' => 3]));
        $this->getQueue()->priority(200)->push(new PriorityJob(['number' => 4]));
        $this->getQueue()->priority(100)->push(new PriorityJob(['number' => 2]));
        $this->runProcess(['php', 'yii', 'queue/run']);

        $this->assertEquals('12345', file_get_contents(PriorityJob::getFileName()));
    }

    public function testListen()
    {
        $this->startProcess(['php', 'yii', 'queue/listen', '1']);
        $job = $this->createSimpleJob();
        $this->getQueue()->push($job);

        $this->assertSimpleJobDone($job);
    }

    public function testLater()
    {
        $this->startProcess(['php', 'yii', 'queue/listen', '1']);
        $job = $this->createSimpleJob();
        $this->getQueue()->delay(2)->push($job);

        $this->assertSimpleJobLaterDone($job, 2);
    }

    public function testRetry()
    {
        $this->startProcess(['php', 'yii', 'queue/listen', '1']);
        $job = new RetryJob(['uid' => uniqid()]);
        $this->getQueue()->push($job);
        sleep(6);

        $this->assertFileExists($job->getFileName());
        $this->assertEquals('aa', file_get_contents($job->getFileName()));
    }

    public function testClear()
    {
        $this->getQueue()->push($this->createSimpleJob());
        $this->runProcess(['php', 'yii', 'queue/clear', '--interactive=0']);
        $actual = (new Query())
            ->from($this->getQueue()->tableName)
            ->where(['channel' => $this->getQueue()->channel])
            ->count('*', $this->getQueue()->db);

        $this->assertEquals(0, $actual);
    }

    public function testRemove()
    {
        $id = $this->getQueue()->push($this->createSimpleJob());
        $this->runProcess(['php', 'yii', 'queue/remove', $id]);
        $actual = (new Query())
            ->from($this->getQueue()->tableName)
            ->where(['channel' => $this->getQueue()->channel, 'id' => $id])
            ->count('*', $this->getQueue()->db);

        $this->assertEquals(0, $actual);
    }

    public function testWaitingCount()
    {
        $this->getQueue()->push($this->createSimpleJob());

        $this->assertEquals(1, $this->getQueue()->getStatisticsProvider()->getWaitingCount());
    }

    public function testDelayedCount()
    {
        $this->getQueue()->delay(5)->push($this->createSimpleJob());

        $this->assertEquals(1, $this->getQueue()->getStatisticsProvider()->getDelayedCount());
    }

    public function testReservedCount()
    {
        $this->getQueue()->messageHandler = function () {
            $this->assertEquals(1, $this->getQueue()->getStatisticsProvider()->getReservedCount());
        };

        $job = $this->createSimpleJob();
        $this->getQueue()->push($job);
        $this->getQueue()->run(false);
    }

    public function testDoneCount()
    {
        $this->getQueue()->messageHandler = function () {
            return true;
        };

        $job = $this->createSimpleJob();
        $this->getQueue()->push($job);
        $this->getQueue()->run(false);

        $this->assertEquals(1, $this->getQueue()->getStatisticsProvider()->getDoneCount());
    }


    protected function tearDown()
    {
        $this->getQueue()->messageHandler = null;
        $this->getQueue()->db->createCommand()
            ->delete($this->getQueue()->tableName)
            ->execute();

        parent::tearDown();
    }
}
