<?php

namespace App\Command;

use App\Service\AuditArchiveService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:archive-audit-logs',
    description: 'Archives old audit logs to a zipped JSON file and removes them from the database.',
)]
class ArchiveAuditLogsCommand extends Command
{
    private $auditArchiveService;

    public function __construct(AuditArchiveService $auditArchiveService)
    {
        parent::__construct();
        $this->auditArchiveService = $auditArchiveService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Audit Log Archival Started');

        $result = $this->auditArchiveService->archiveLogs();

        if ($result['status'] === 'success') {
            if ($result['archived_count'] > 0) {
                $io->success($result['message']);
            } else {
                $io->info($result['message']);
            }
            return Command::SUCCESS;
        } else {
            $io->error($result['message']);
            return Command::FAILURE;
        }
    }
}
