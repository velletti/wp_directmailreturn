<?php
namespace WEBprofil\WpDirectmailreturn\Command;

use TYPO3\CMS\Core\Information\Typo3Version;
use WEBprofil\WpDirectmailreturn\Utility\FetchBouncesUtility;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class UpdateSlugCommandController
 * @author Jörg Velletti <typo3@velletti.de>
 * @package WEBprofil\wp_directmailreturn\
 */
class AnalyzeMailCommand extends Command {

    /**
     * @var array
     */
    private $allowedTables = [] ;

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure()
    {
        $this->setDescription('Analyses emails sent by the extension direct mail.')
            ->setHelp('Get list of Options: ' . LF . 'use the --help option or use -vvv to get some debug output in case of problems ')
            ->addArgument(
                'rundry',
                InputArgument::OPTIONAL,
                'if rundry is set, it will only analyze if a Login to the configured mailbox will work'
            )
            ->addOption(
                'amount',
                'a',
                InputOption::VALUE_OPTIONAL,
                'number of emails to be analyze on each run (must be integer)' )
        ;

    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->analyzeCommand( $input,  $output ) ) {
            return 1 ;
        }
        return 0 ;

    }
    /**
     * Gives an open permission to the deputy after $days
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return boolean
     */

    public function analyzeCommand(InputInterface $input, OutputInterface $output  ) {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());




        /** @var FetchBouncesUtility $fetchUtil */
        $fetchUtil =  GeneralUtility::makeInstance('WEBprofil\WpDirectmailreturn\Utility\FetchBouncesUtility');


        if ( !$fetchUtil->init($input) ) {
            $fetchUtil->logger->log( LogLevel::ERROR, 'TYPO3 wp_directmailreturn ' . $fetchUtil->errorMsg );
            $io->writeln($fetchUtil->errorMsg ) ;
            die;
        }
        if( !$fetchUtil->initLocker() ) {
            $io->writeln($fetchUtil->errorMsg ) ;
            die;
        }
        if ($input->getArgument('rundry')) {
            $fetchUtil->rundry = true  ;
              if( $io->getVerbosity() > 16 ) {
                  $io->writeln("started with argument rundry. we will just check if everything works and do NOTHING!" ) ;
              }
        }

        $fetchUtil->logger->log( LogLevel::INFO, 'TYPO3 wp_directmailreturn Cron: Run started!');
        if( $fetchUtil->amount < 1 ) {
            $io->writeln("Host: " . $fetchUtil->host ) ;
            $io->writeln("user: " . $fetchUtil->user ) ;
            $io->writeln("port: " . $fetchUtil->port ) ;
            $io->writeln("Type: " . $fetchUtil->type ) ;
            $io->writeln("skipValidation: " . $fetchUtil->skipCertValidation ) ;
            $io->writeln("# Number of mails per run: " . $fetchUtil->amount ) ;
            die;
        }
        $io->writeln('max rows to be analyzed is set to '. $fetchUtil->amount );
        $mbox = $fetchUtil->getMbox( $io) ;

        if ($mbox==false) {
            $fetchUtil->logger->log(LogLevel::ERROR , $fetchUtil->errorMsg );
            $this->mail($fetchUtil->errorEmail, "[WP-ERR-1] wp_directmail run into error on Server " . $_SERVER['HOSTNAME'] , $fetchUtil->errorMsg );
            $io->writeln($fetchUtil->errorMsg );
            die('');
        } elseif ( $io->getVerbosity() > 128 ) {
            $io->writeln( "got Mailbox from " .  $fetchUtil->host . " for User : " . $fetchUtil->user);
        }

        for ( $i = 1 ; $i <= $fetchUtil->amount ; $i++ ) {
            if ( $io->getVerbosity() > 128 ) {
                $io->writeln( "Read from Mailbox : " .  $i );
            }
            try {
                $temp = imap_uid( $mbox, $i )  ;
                if( $temp) {
                    $msgArray[] = $temp ;
                }
            } catch(Exception $e) {
                break;
            }
        }

        if( $msgArray ) {
             $total = count($msgArray);
        } else {
             $io->writeln("No emails found in inbox");
             return true;
        }
        if( $io->getVerbosity() > 16 ) {
            $progress = $io->createProgressBar($total) ;
        }

        if( $msgArray ) {
            $cnt=0;

            /** @var \WEBprofil\WpDirectmailreturn\Utility\ReadMail $readMail */
            $readMail = GeneralUtility::makeInstance('WEBprofil\\WpDirectmailreturn\\Utility\\ReadMail');
            $report = "" ;


            $checked = 0  ;
            foreach( $msgArray as $key => $msgId ) {

                $checked ++ ;

                @set_time_limit(30);
                if( $io->getVerbosity()  > 128 ) {
                    $io->writeln("Try to  analyze Imap Mail (" . $key . " / " . $fetchUtil->amount . ") MsgNo:  " . $msgId);
                }
                if ( !$fetchUtil->rundry ) {
                    $temp = $fetchUtil->analyze( $readMail ,  $mbox , $msgId  );
                    $report .= $temp ;
                    if( $io->getVerbosity() > 16 ) {
                        $progress->advance();
                        if( $io->getVerbosity()  > 128 ) {
                            $io->writeln(" "  );
                            $io->writeln("Analyze Result: " . $temp );
                        }
                    }
                    $cnt++;
                    if ($cnt >= $fetchUtil->amount) {
                        break ;
                    } ;
                }

            }
        }
        imap_close($mbox, CL_EXPUNGE);

        if ( $cnt > 0 && !$fetchUtil->rundry) {
            $this->mail($fetchUtil->successEmail , $_SERVER['HTTP_HOST'] . " " . $_SERVER['HOSTNAME']. " : " . $cnt . " Bounced email(s) were analysed" , $report ) ;
        }
        $fetchUtil->logger->log( LogLevel::NOTICE, 'TYPO3 wp_directmailreturn Cron: Run ended successfully! '.$cnt.' mails have been analysed!');
        if( $io->getVerbosity() > 16 ) {
            $progress->finish();
            if ( $total > 0 ) {
                if( $io->getVerbosity()  > 128 ) {
                    $io->writeln(" ") ;
                    $io->writeln(" ") ;
                }
                $io->writeln(" ") ;
                $io->writeln("Finished: analyzed '"  . $total .  "' records ");
            }

        }

        return true;
    }

     /*
     * send a mail with build-in swiftmailer
     * @param \mixed $to array(key1 => array('email' => 'name1@domain.tld', 'name' => 'Name1'), key2 => array('email' => 'name2@domain.tld', 'name' => 'Name2')) or just a string with the email-address
     * @param \string $subject
     * @param \string $plain
     * @return \boolean true, if mail should be send - false, if parameter errors are given
     */
    public function mail($to, $subject, $plain): bool
    {
       if(strcmp($to, '')!=0 && GeneralUtility::validEmail( $to ))
       {
           $fromName = $_SERVER['HTTP_HOST'] ;
           if ( $fromName == '' ) {
               $fromName = $_SERVER['SERVER_NAME'] ;
           }
           $fromEmail = \TYPO3\CMS\Core\Utility\MailUtility::getSystemFromAddress();

           if ( $fromEmail == "no-reply@example.com" ) {
               $fromEmail = 'noreply@' . $fromName ;
           }
           if ( ! GeneralUtility::validEmail( $fromEmail )) {
               $fromEmail = $to ;
           }

           /** @var \TYPO3\CMS\Core\Mail\MailMessage $message // make instance of swiftmailer */
          $message = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Mail\\MailMessage');

          $message->setFrom(array($fromEmail => $fromName));

          // to
          $recipients = array();
          if (is_array($to)) {
            foreach ($to as $pair) {
              if (GeneralUtility::validEmail($pair['email'] )) {
                if (trim($pair['name'])) {
                  $recipients[$pair['email']] = $pair['name'];
                } else {
                  $recipients[] = $pair['email'];
                }
              }
            }
          } else {
            $recipients[] = $to;
          }

          if (!count($recipients)) {
            return false;
          }

          $message->setTo($recipients);

          // subject
          $message->setSubject($subject);
           $html = nl2br( $plain ) ;
          // html

           /** @var Typo3Version $tt */
           $tt = GeneralUtility::makeInstance( \TYPO3\CMS\Core\Information\Typo3Version::class ) ;

           if( $tt->getMajorVersion()  < 10 ) {
               $message->setBody($html, 'text/html', 'utf-8');
               // plain
               if ($plain) {
                   $message->addPart($plain, 'text/plain', 'utf-8');
               }
           } else {
               $message->html( nl2br( $html )  , 'utf-8'  );
                if ($plain) {
                    $message->text($plain, 'utf-8');
                }
           }




          // send
          $message->send();
       }

          return true;
    }
}
?>
