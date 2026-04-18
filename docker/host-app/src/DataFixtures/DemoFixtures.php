<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DemoFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $users = $this->seedUsers($manager);
        $departments = $this->seedDepartments($manager);
        $slaPolicy = $this->seedSlaPolicy($manager);
        $tags = $this->seedTags($manager);

        $manager->flush();

        $this->seedAgentProfiles($manager, $users);
        $manager->flush();

        $this->seedTickets($manager, $users, $departments, $slaPolicy, $tags);
        $manager->flush();
    }

    /** @return array{admin:User,agents:User[],customers:User[]} */
    private function seedUsers(ObjectManager $manager): array
    {
        $password = 'password';

        $make = function (string $name, string $email, array $roles) use ($manager, $password) {
            $u = (new User())->setName($name)->setEmail($email)->setRoles($roles);
            $u->setPassword($this->hasher->hashPassword($u, $password));
            $manager->persist($u);

            return $u;
        };

        return [
            'admin' => $make('Alice Admin', 'alice@demo.test', ['ROLE_ESCALATED_ADMIN', 'ROLE_ESCALATED_AGENT']),
            'agents' => [
                $make('Bob Agent', 'bob@demo.test', ['ROLE_ESCALATED_AGENT']),
                $make('Carol Agent', 'carol@demo.test', ['ROLE_ESCALATED_AGENT']),
            ],
            'customers' => [
                $make('Frank Customer', 'frank@acme.example', []),
                $make('Grace Customer', 'grace@acme.example', []),
                $make('Henry Customer', 'henry@globex.example', []),
            ],
        ];
    }

    /** @return array<string,Department> */
    private function seedDepartments(ObjectManager $manager): array
    {
        $dept = fn (string $name, string $slug, string $desc) => (new Department())
            ->setName($name)->setSlug($slug)->setDescription($desc)->setIsActive(true);

        $departments = [
            'support' => $dept('Support', 'support', 'General product support.'),
            'billing' => $dept('Billing', 'billing', 'Invoices, refunds, subscriptions.'),
        ];
        foreach ($departments as $d) {
            $manager->persist($d);
        }

        return $departments;
    }

    private function seedSlaPolicy(ObjectManager $manager): SlaPolicy
    {
        $sla = (new SlaPolicy())
            ->setName('Standard')
            ->setDescription('Default SLA for most tickets.')
            ->setIsDefault(true)
            ->setFirstResponseHours(['low' => 24, 'medium' => 8, 'high' => 4, 'urgent' => 2, 'critical' => 1])
            ->setResolutionHours(['low' => 72, 'medium' => 48, 'high' => 24, 'urgent' => 8, 'critical' => 4])
            ->setBusinessHoursOnly(false)
            ->setIsActive(true);
        $manager->persist($sla);

        return $sla;
    }

    /** @return Tag[] */
    private function seedTags(ObjectManager $manager): array
    {
        $tags = [];
        foreach (['bug' => '#ef4444', 'refund' => '#10b981', 'billing' => '#f59e0b'] as $slug => $color) {
            $t = (new Tag())->setName(ucfirst($slug))->setSlug($slug)->setColor($color);
            $manager->persist($t);
            $tags[$slug] = $t;
        }

        return $tags;
    }

    /** @param array{admin:User,agents:User[],customers:User[]} $users */
    private function seedAgentProfiles(ObjectManager $manager, array $users): void
    {
        foreach ([$users['admin'], ...$users['agents']] as $u) {
            $profile = (new AgentProfile());
            $profile->setUserId($u->getId());
            $profile->setAgentType(AgentProfile::TYPE_FULL);
            $profile->setMaxTickets(25);
            $manager->persist($profile);
        }
    }

    /**
     * @param array{admin:User,agents:User[],customers:User[]} $users
     * @param array<string,Department>                         $departments
     * @param Tag[]                                            $tags
     */
    private function seedTickets(ObjectManager $manager, array $users, array $departments, SlaPolicy $sla, array $tags): void
    {
        $subjects = [
            'Unable to log in — password reset email not arriving',
            'Feature request: bulk-export tickets as CSV',
            'Refund for duplicate charge on invoice #A-2847',
            'Integration with Slack stopped posting after last update',
            'Getting 502 from API endpoint /v2/contacts',
            'SSO configuration questions',
            'Cannot upload files larger than 10MB',
            'Billing: can we switch from monthly to annual mid-cycle?',
        ];
        $statuses = [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED];
        $priorities = [Ticket::PRIORITY_LOW, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT];

        foreach ($subjects as $i => $subject) {
            $customer = $users['customers'][$i % count($users['customers'])];
            $agent = $users['agents'][$i % count($users['agents'])];
            $department = array_values($departments)[$i % count($departments)];

            $ticket = (new Ticket())
                ->setReference(sprintf('ESC-%05d', $i + 1))
                ->setSubject($subject)
                ->setDescription('Demo ticket seeded on boot. Status: '.$statuses[$i % count($statuses)])
                ->setStatus($statuses[$i % count($statuses)])
                ->setPriority($priorities[$i % count($priorities)])
                ->setChannel(Ticket::CHANNEL_WEB)
                ->setRequesterClass(User::class)
                ->setRequesterId($customer->getId())
                ->setAssignedTo($agent->getId())
                ->setDepartment($department)
                ->setSlaPolicy($sla);

            $manager->persist($ticket);
            $manager->flush();

            foreach (range(0, $i % 3) as $r) {
                $reply = (new Reply())
                    ->setTicket($ticket)
                    ->setAuthorClass(User::class)
                    ->setAuthorId((0 === $r ? $customer : $agent)->getId())
                    ->setBody('Demo reply #'.($r + 1).' on '.$subject)
                    ->setType('reply')
                    ->setIsInternalNote(false);
                $manager->persist($reply);
            }
        }
    }
}
