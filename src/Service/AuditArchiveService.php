<?php

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AuditArchiveService
{
    private $em;
    private $configService;
    private $params;

    public function __construct(EntityManagerInterface $em, AppConfigService $configService, ParameterBagInterface $params)
    {
        $this->em = $em;
        $this->configService = $configService;
        $this->params = $params;
    }

    public function archiveLogs(): array
    {
        // 1. Determine the cutoff date based on settings
        $days = (int)$this->configService->get('audit_log_archive_days', 180);
        
        $cutoffDate = new \DateTime();
        $cutoffDate->modify("-{$days} days");

        // 2. Query old audit logs
        $qb = $this->em->createQueryBuilder();
        $qb->select('a')
           ->from(AuditLog::class, 'a')
           ->where('a.createdAt <= :cutoff')
           ->setParameter('cutoff', $cutoffDate)
           ->orderBy('a.createdAt', 'ASC');
           
        $logs = $qb->getQuery()->getResult();
        
        if (empty($logs)) {
            return [
                'status' => 'success',
                'archived_count' => 0,
                'message' => 'No logs older than ' . $days . ' days found.'
            ];
        }

        // 3. Format as JSON
        $data = [];
        foreach ($logs as $log) {
            $data[] = [
                'id' => $log->getId(),
                'action' => $log->getAction(),
                'details' => $log->getDetailsJson(),
                'createdAt' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'manager_id' => $log->getManager() ? $log->getManager()->getId() : null,
                'organization_id' => $log->getOrganization() ? $log->getOrganization()->getId() : null,
                'ip_address' => $log->getIpAddress(),
                'user_agent' => $log->getUserAgent(),
            ];
        }

        // 4. Create directory and files: resolve the destination folder dynamically from global settings config
        $fs = new Filesystem();
        $projectDir = $this->params->get('kernel.project_dir');
        $archiveSubdir = $this->configService->get('audit_log_archive_dir', '/var/log/audit_archives');
        $archiveDir = $projectDir . $archiveSubdir;
        
        if (!$fs->exists($archiveDir)) {
            $fs->mkdir($archiveDir);
        }

        $timestamp = (new \DateTime())->format('Ymd_His');
        $jsonFileName = 'audit_logs_' . $timestamp . '.json';
        $jsonFilePath = $archiveDir . '/' . $jsonFileName;
        $zipFileName = 'audit_logs_' . $timestamp . '.zip';
        $zipFilePath = $archiveDir . '/' . $zipFileName;

        // Write JSON file
        file_put_contents($jsonFilePath, json_encode($data, JSON_PRETTY_PRINT));

        // 5. Zip it
        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath, \ZipArchive::CREATE) === TRUE) {
            $zip->addFile($jsonFilePath, $jsonFileName);
            $zip->close();
            
            // Delete original JSON file
            $fs->remove($jsonFilePath);
        } else {
            return [
                'status' => 'error',
                'archived_count' => 0,
                'message' => 'Failed to create zip archive.'
            ];
        }

        // 6. Delete archived records from database
        $archivedCount = count($logs);
        
        // Delete in batches to avoid memory/lock issues if there are many
        $batchSize = 100;
        $i = 0;
        foreach ($logs as $log) {
            $this->em->remove($log);
            $i++;
            if (($i % $batchSize) === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->em->flush();

        return [
            'status' => 'success',
            'archived_count' => $archivedCount,
            'archive_file' => $zipFileName,
            'message' => "Successfully archived and deleted {$archivedCount} logs older than {$days} days."
        ];
    }
}
