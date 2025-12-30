<?php

declare(strict_types=1);

/**
 * HTTP/2 Multiplexing Usage Example
 * 
 * Demonstrates how to use HTTP/2 multiplexing features in HybridPHP.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Server\Http2\MultiplexingManager;
use HybridPHP\Core\Server\Http2\StreamManager;
use HybridPHP\Core\Server\Http2\FlowController;
use HybridPHP\Core\Server\Http2\StreamScheduler;
use HybridPHP\Core\Server\Http2\StreamPriority;
use HybridPHP\Core\Server\Http2\Http2Config;

echo "=== HTTP/2 Multiplexing Example ===\n\n";

// Create configuration
$config = new Http2Config([
    'max_concurrent_streams' => 100,
    'initial_window_size' => 65535,
    'max_frame_size' => 16384,
]);

// Initialize components
$streamManager = new StreamManager($config);
$multiplexingManager = new MultiplexingManager($streamManager, $config);
$flowController = new FlowController($config->getInitialWindowSize());
$scheduler = new StreamScheduler();

echo "1. Stream Management\n";
echo "--------------------\n";

// Create streams with different priorities
$stream1 = $streamManager->createStream(1);
$stream2 = $streamManager->createStream(3);
$stream3 = $streamManager->createStream(5);

echo "Created streams: 1, 3, 5\n";

// Set priorities
$streamManager->setStreamPriority(1, 32);  // Default priority
$streamManager->setStreamPriority(3, 64);  // Higher priority
$streamManager->setStreamPriority(5, 16);  // Lower priority

echo "Set priorities: Stream 1=32, Stream 3=64, Stream 5=16\n";

// Get streams by priority
$sortedStreams = $streamManager->getStreamsByPriority();
echo "Streams sorted by priority: ";
echo implode(', ', array_map(fn($s) => $s->getId(), $sortedStreams)) . "\n\n";

echo "2. Stream States\n";
echo "----------------\n";

// Open a stream
$stream1->open();
echo "Stream 1 state: {$stream1->getState()}\n";
echo "Stream 1 can send: " . ($stream1->canSend() ? 'yes' : 'no') . "\n";
echo "Stream 1 can receive: " . ($stream1->canReceive() ? 'yes' : 'no') . "\n";

// Half-close local side
$stream1->closeLocal();
echo "After closeLocal - State: {$stream1->getState()}\n";
echo "Can send: " . ($stream1->canSend() ? 'yes' : 'no') . "\n\n";

echo "3. Flow Control\n";
echo "---------------\n";

// Initialize flow control for streams
$flowController->initStream(1);
$flowController->initStream(3);

echo "Initial connection window: {$flowController->getConnectionSendWindow()}\n";
echo "Stream 1 send window: {$flowController->getStreamSendWindow(1)}\n";

// Simulate sending data
$flowController->consumeSendWindow(1, 1000);
echo "After sending 1000 bytes:\n";
echo "  Connection window: {$flowController->getConnectionSendWindow()}\n";
echo "  Stream 1 window: {$flowController->getStreamSendWindow(1)}\n";

// Simulate receiving WINDOW_UPDATE
$flowController->processWindowUpdate(1, 500);
echo "After WINDOW_UPDATE +500:\n";
echo "  Stream 1 window: {$flowController->getStreamSendWindow(1)}\n\n";

echo "4. Stream Scheduling\n";
echo "--------------------\n";

// Add streams to scheduler with dependencies
$scheduler->addStream(1, weight: 32);
$scheduler->addStream(3, weight: 64, dependency: 1);
$scheduler->addStream(5, weight: 16, dependency: 1);

echo "Dependency tree:\n";
echo "  Root\n";
echo "    └── Stream 1 (weight: 32)\n";
echo "        ├── Stream 3 (weight: 64)\n";
echo "        └── Stream 5 (weight: 16)\n\n";

// Mark streams as ready
$scheduler->markReady(3);
$scheduler->markReady(5);

echo "Ready streams: " . implode(', ', $scheduler->getReadyStreams()) . "\n";

// Get next stream (weighted selection)
$next = $scheduler->getNextStream();
echo "Next stream to process: {$next}\n";
echo "(Stream 3 has higher weight, so it's more likely to be selected)\n\n";

echo "5. Exclusive Dependencies\n";
echo "-------------------------\n";

$scheduler2 = new StreamScheduler();
$scheduler2->addStream(1, weight: 32);
$scheduler2->addStream(3, weight: 16, dependency: 1);
$scheduler2->addStream(5, weight: 16, dependency: 1);

echo "Before exclusive:\n";
$tree = $scheduler2->getDependencyTree();
printTree($tree, '  ');

// Add stream 7 as exclusive child of stream 1
$scheduler2->addStream(7, weight: 64, dependency: 1, exclusive: true);

echo "\nAfter adding Stream 7 as exclusive child of Stream 1:\n";
$tree = $scheduler2->getDependencyTree();
printTree($tree, '  ');

echo "\n6. Multiplexing Statistics\n";
echo "--------------------------\n";

$stats = $multiplexingManager->getStats();
echo "Active streams: {$stats['active_streams']}\n";
echo "Pending streams: {$stats['pending_streams']}\n";
echo "Connection window: {$stats['connection_window']}\n";
echo "Max concurrent: {$stats['max_concurrent']}\n\n";

$flowStats = $flowController->getStats();
echo "Flow control stats:\n";
echo "  Bytes sent: {$flowStats['bytes_sent']}\n";
echo "  Window updates received: {$flowStats['window_updates_received']}\n";
echo "  Active streams: {$flowStats['active_streams']}\n\n";

echo "=== Example Complete ===\n";

/**
 * Helper function to print dependency tree
 */
function printTree(array $tree, string $indent = ''): void
{
    foreach ($tree as $streamId => $info) {
        $ready = $info['ready'] ? ' [READY]' : '';
        $exclusive = $info['exclusive'] ? ' (exclusive)' : '';
        echo "{$indent}Stream {$streamId} (weight: {$info['weight']}){$exclusive}{$ready}\n";
        if (!empty($info['children'])) {
            printTree($info['children'], $indent . '  ');
        }
    }
}
