<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Tests\Support\TestCase;
use RuntimeException;

final class CancellationTest extends TestCase
{
    public function testFreshTokenDoesNotThrowOrReportCancellation(): void
    {
        $token = new Cancellation();
        self::assertFalse($token->isCancelled());
        $token->throwIfCancelled();
        self::assertFalse($token->isCancelled());
    }

    public function testDefaultCancellationWithoutSubscribersRemainsIdempotent(): void
    {
        $token = new Cancellation();
        $token->cancel();
        self::assertTrue($token->isCancelled());
        $token->cancel(new RuntimeException('Later reasons must be ignored.'));

        $reason = self::cancellationReason($token);
        self::assertSame('Operation cancelled.', $reason->getMessage());
        self::assertNull($reason->getPrevious());
        self::assertSame($reason, self::cancellationReason($token));
    }

    public function testCurrentAndLateSubscribersReceiveTheSameReasonExactlyOnce(): void
    {
        $token = new Cancellation();
        $seen = [];
        $subscriber = static function (CancelledException $reason) use (&$seen): void {
            $seen[] = $reason;
        };
        $token->subscribe($subscriber);
        $token->subscribe($subscriber);
        $token->cancel();
        $token->cancel();
        $token->subscribe($subscriber);

        self::assertCount(3, $seen);
        self::assertSame($seen[0], $seen[1]);
        self::assertSame($seen[0], $seen[2]);
        self::assertSame($seen[0], self::cancellationReason($token));
    }

    public function testLateSubscriberAndThrowShareADefaultReasonAfterUnobservedCancellation(): void
    {
        $token = new Cancellation();
        $token->cancel();
        $seen = null;
        $unsubscribe = $token->subscribe(static function (CancelledException $reason) use (&$seen): void {
            $seen = $reason;
        });
        $unsubscribe();
        $unsubscribe();

        self::assertInstanceOf(CancelledException::class, $seen);
        self::assertSame($seen, self::cancellationReason($token));
        self::assertTrue($token->isCancelled());
    }

    public function testUnsubscribePreventsNotificationAndRemainsSafeAfterCancellation(): void
    {
        $token = new Cancellation();
        $calls = 0;
        $unsubscribe = $token->subscribe(static function () use (&$calls): void { ++$calls; });
        $unsubscribe();
        $unsubscribe();
        $token->cancel();
        $unsubscribe();

        self::assertSame(0, $calls);
        self::assertTrue($token->isCancelled());
        self::assertNull(self::cancellationReason($token)->getPrevious());
    }

    public function testSuppliedCancelledExceptionIsNotWrappedOrReplaced(): void
    {
        $previous = new RuntimeException('Original cause.');
        $supplied = new CancelledException('Caller cancellation.', 17, $previous);
        $token = new Cancellation();
        $token->cancel($supplied);
        $token->cancel(new CancelledException('Ignored.'));
        $seen = null;
        $token->subscribe(static function (CancelledException $reason) use (&$seen): void {
            $seen = $reason;
        });

        self::assertSame($supplied, $seen);
        self::assertSame($supplied, self::cancellationReason($token));
        self::assertSame($previous, $seen->getPrevious());
        self::assertSame(17, $seen->getCode());
    }

    public function testOtherThrowableIsWrappedOnceAndRetainedAsPrevious(): void
    {
        $previous = new RuntimeException('Underlying failure.', 23);
        $token = new Cancellation();
        $seen = null;
        $token->subscribe(static function (CancelledException $reason) use (&$seen): void {
            $seen = $reason;
        });
        $token->cancel($previous);
        $token->cancel();

        self::assertInstanceOf(CancelledException::class, $seen);
        self::assertSame('Operation cancelled.', $seen->getMessage());
        self::assertSame(0, $seen->getCode());
        self::assertSame($previous, $seen->getPrevious());
        self::assertSame($seen, self::cancellationReason($token));
    }

    public function testReentrantCancellationAndLateSubscriptionKeepTheOriginalReason(): void
    {
        $token = new Cancellation();
        $seen = [];
        $token->subscribe(static function (CancelledException $reason) use ($token, &$seen): void {
            $seen[] = ['first', $reason, $token->isCancelled()];
            $token->cancel(new RuntimeException('Ignored during notification.'));
            $token->subscribe(static function (CancelledException $late) use (&$seen): void {
                $seen[] = ['late', $late, true];
            });
        });
        $token->subscribe(static function (CancelledException $reason) use (&$seen): void {
            $seen[] = ['second', $reason, true];
        });
        $token->cancel();

        self::assertSame(['first', 'late', 'second'], array_column($seen, 0));
        self::assertSame([true, true, true], array_column($seen, 2));
        self::assertSame($seen[0][1], $seen[1][1]);
        self::assertSame($seen[0][1], $seen[2][1]);
        self::assertNull($seen[0][1]->getPrevious());
    }

    public function testSubscriberFailureIsReportedAndDoesNotPreventOtherNotifications(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'crab-cancellation-log-');
        self::assertNotFalse($log);
        $this->deferCleanup(static function () use ($log): void { unlink($log); });
        $previousLog = ini_set('error_log', $log);
        self::assertNotFalse($previousLog);
        $this->deferCleanup(static function () use ($previousLog): void { ini_set('error_log', $previousLog); });
        $token = new Cancellation();
        $seen = [];
        $token->subscribe(static function (CancelledException $reason) use (&$seen): void {
            $seen[] = $reason;
            throw new RuntimeException('Intentional cancellation subscriber failure.');
        });
        $token->subscribe(static function (CancelledException $reason) use (&$seen): void {
            $seen[] = $reason;
        });
        $token->cancel();

        self::assertCount(2, $seen);
        self::assertSame($seen[0], $seen[1]);
        self::assertSame($seen[0], self::cancellationReason($token));
        self::assertStringContainsString(
            '[Crab] Cancellation callback failed: Intentional cancellation subscriber failure.',
            file_get_contents($log),
        );
    }

    private static function cancellationReason(Cancellation $token): CancelledException
    {
        try {
            $token->throwIfCancelled();
        } catch (CancelledException $reason) {
            return $reason;
        }
        self::fail('Cancelled token did not throw.');
    }
}
