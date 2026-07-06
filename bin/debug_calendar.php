<?php
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/../vendor/autoload.php';

$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine.orm.default_entity_manager');

$user = $em->getRepository(\App\Entity\OrgUser::class)->find(673);
if (!$user) { echo "User not found\n"; exit; }

$monthStr = '2026-07';
$startOfMonth = new \DateTime($monthStr . '-01 00:00:00');
$endOfMonth = clone $startOfMonth;
$endOfMonth->modify('last day of this month')->setTime(23, 59, 59);

$qb = $em->createQueryBuilder();
$qb->select('s')->from(\App\Entity\WorkSession::class, 's')
    ->where('s.orgUser = :orgUser')
    ->andWhere('s.startedAt >= :start')
    ->andWhere('s.startedAt <= :end')
    ->setParameter('orgUser', $user)
    ->setParameter('start', $startOfMonth)
    ->setParameter('end', $endOfMonth)
    ->orderBy('s.startedAt', 'ASC');

$sessions = $qb->getQuery()->getResult();
echo "Total sessions fetched: " . count($sessions) . "\n";

$calendarData = [];
$daysInMonth = (int) $startOfMonth->format('t');
for ($i = 1; $i <= $daysInMonth; $i++) {
    $dateKey = $startOfMonth->format('Y-m-') . str_pad($i, 2, '0', STR_PAD_LEFT);
    $calendarData[$dateKey] = [
        'day' => $i,
        'totalSeconds' => 0,
        'sessions' => []
    ];
}

foreach ($sessions as $session) {
    $dateKey = $session->getStartedAt()->format('Y-m-d');
    if (isset($calendarData[$dateKey])) {
        $calendarData[$dateKey]['totalSeconds'] += $session->getDurationSeconds() ?? 0;
        $calendarData[$dateKey]['sessions'][] = $session->getId();
    }
}
print_r($calendarData['2026-07-05']);
