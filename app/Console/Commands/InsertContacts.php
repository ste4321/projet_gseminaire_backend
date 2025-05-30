<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contact;

class InsertContacts extends Command
{
    protected $signature = 'insert:contacts';
    protected $description = 'Insère deux contacts dans la base de données';

    public function handle()
    {
        Contact::create([
            'name' => 'John Doe',
            'email' => 'johndoe@example.com',
            'message' => 'Ceci est un message de test 1.',
        ]);

        Contact::create([
            'name' => 'Jane Doe',
            'email' => 'janedoe@example.com',
            'message' => 'Ceci est un message de test 2.',
        ]);

        $this->info('Deux contacts ont été insérés avec succès.');
    }
}
