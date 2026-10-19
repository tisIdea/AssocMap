{{ $person ? collect([$person->first_name, $person->middle_name, $person->last_name, $person->suffix])->filter(fn ($part) => filled($part))->implode(' ') : 'No active President assigned' }}
