<?php

declare(strict_types=1);

namespace Drupal\Tests\perf_redis_failopen\Unit;

use Drupal\perf_redis_failopen\Client\TimeoutPhpRedis;
use Drupal\redis\Client\PhpRedis;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Keeps TimeoutPhpRedis::getClient() in step with the redis module.
 *
 * TimeoutPhpRedis copies PhpRedis::getClient() because the parent connects
 * inside that one method and offers no hook for a timeout or a TLS context.
 * A copy drifts silently when the redis module changes, so this test compares
 * the two methods token by token, ignoring comments, whitespace, the list()
 * versus [] spelling, and the one place where they are meant to differ: the
 * connect call (and the read-timeout option that goes with it).
 *
 * If this test fails after a redis module update, the update changed
 * getClient(): read the upstream change, port it into TimeoutPhpRedis, and
 * only then adjust the expected shape here. Do not loosen the comparison.
 *
 * @group perf_redis_failopen
 */
#[Group('perf_redis_failopen')]
final class TimeoutPhpRedisParityTest extends UnitTestCase {

  /**
   * The two methods are the same apart from the connect call.
   */
  public function testGetClientMatchesTheRedisModuleExceptForTheConnectCall(): void {
    $this->assertSame(
      $this->normalised($this->source(PhpRedis::class)),
      $this->normalised($this->source(TimeoutPhpRedis::class)),
      'TimeoutPhpRedis::getClient() has drifted from PhpRedis::getClient().'
    );
  }

  /**
   * The comparison can fail: a changed upstream line is noticed.
   *
   * Without this, a normaliser that strips too much would make the test above
   * pass whatever upstream does.
   */
  public function testTheComparisonNoticesChangesInTheRedisModule(): void {
    $upstream = $this->source(PhpRedis::class);
    $changed = str_replace('$client->select($base);', '$client->select($base + 1);', $upstream);
    $this->assertNotSame($upstream, $changed, 'The probe line must exist in the redis module.');
    $this->assertNotSame($this->normalised($changed), $this->normalised($this->source(TimeoutPhpRedis::class)));
    $removed = str_replace('$client->auth($password);', '', $upstream);
    $this->assertNotSame($this->normalised($removed), $this->normalised($this->source(TimeoutPhpRedis::class)));
  }

  /**
   * Returns the source text of a class's getClient() method.
   */
  private function source(string $class): string {
    $method = new \ReflectionMethod($class, 'getClient');
    $lines = file((string) $method->getFileName());
    $this->assertIsArray($lines);
    return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
  }

  /**
   * Strips comments and whitespace and maps the connect call to a marker.
   */
  private function normalised(string $source): string {
    $code = '';
    foreach (token_get_all('<?php ' . $source) as $token) {
      if (is_array($token)) {
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], TRUE)) {
          continue;
        }
        $code .= strtolower($token[1]) === 'null' ? 'NULL' : $token[1];
        continue;
      }
      $code .= $token;
    }
    // The old and new spelling of destructuring.
    $code = (string) preg_replace('/list\(([^()]*)\)=/', '[$1]=', $code);
    // The redis module connects with its stock call, ours with timeouts.
    $code = (string) preg_replace('/if\(\$persistent\)\{\$client->pconnect\(\$host,\$port\);\}else\{\$client->connect\(\$host,\$port\);\}/', '<CONNECT>', $code);
    return (string) preg_replace('/\$this->connect\(\$client,\(string\)\$host,\(int\)\$port,\(bool\)\$persistent\);\$client->setOption\(\\\\Redis::OPT_READ_TIMEOUT,\$this->readTimeout\);/', '<CONNECT>', $code);
  }

}
