<?php

namespace App\Console\Commands;

use App\Models\Corporation;
use App\Models\User;
use App\Support\EventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateCorporation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-corporation
        {--corporation= : Name of the corporation}
        {--name= : Name of the first admin user}
        {--email= : Email address of the first admin user}
        {--password= : Password of the first admin user (prompted if omitted)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a corporation with its first admin user (D3: customers are provisioned manually)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $input = [
            'corporation' => $this->option('corporation') ?? text('Corporation name', required: true),
            'name' => $this->option('name') ?? text('Admin user name', required: true),
            'email' => $this->option('email') ?? text('Admin email address', required: true),
            'password' => $this->option('password') ?? password('Admin password', required: true),
        ];

        $validator = Validator::make($input, [
            'corporation' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', Rules\Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($input) {
            $corporation = Corporation::create(['name' => $input['corporation']]);

            $user = $corporation->users()->forceCreate([
                'name' => $input['name'],
                'email' => $input['email'],
                'email_verified_at' => now(),
                'password' => $input['password'],
            ]);

            // S2: a corporation always has at least one staff member — the founder.
            $staff = $corporation->staff()->create(['name' => $input['name']]);

            EventRecorder::record($corporation, 'corporation.created', subject: $corporation);
            EventRecorder::record($corporation, 'user.created', subject: $user);
            EventRecorder::record($corporation, 'staff.created', subject: $staff);

            return $user;
        });

        $this->info("Created corporation '{$user->corporation->name}' with admin user '{$user->email}'.");

        return self::SUCCESS;
    }
}
