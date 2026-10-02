<?php

namespace App\Enums;

/**
 * The only intents a customer reply can be classified as. AI output outside this list is rejected.
 */
enum CustomerReplyIntent: string
{
    case Interested = 'interested';
    case PriceObjection = 'price_objection';
    case Question = 'question';
    case ReadyToBook = 'ready_to_book';
    case WantsCallback = 'wants_callback';
    case NotInterested = 'not_interested';
    case NeedsMoreInformation = 'needs_more_information';
    case WrongNumber = 'wrong_number';
    case Complaint = 'complaint';
    case Spam = 'spam';
    case Unclear = 'unclear';

    public function label(): string
    {
        return match ($this) {
            self::Interested => 'Interested',
            self::PriceObjection => 'Price objection',
            self::Question => 'Question',
            self::ReadyToBook => 'Ready to book',
            self::WantsCallback => 'Wants callback',
            self::NotInterested => 'Not interested',
            self::NeedsMoreInformation => 'Needs more information',
            self::WrongNumber => 'Wrong number',
            self::Complaint => 'Complaint',
            self::Spam => 'Spam',
            self::Unclear => 'Unclear',
        };
    }

    /**
     * Short guidance for the classifier prompt.
     */
    public function description(): string
    {
        return match ($this) {
            self::Interested => 'positive or open to proceeding, but not yet booking',
            self::PriceObjection => 'pushes back on price, asks for a discount or counter-offers an amount',
            self::Question => 'asks a question about the estimate, work, timing or terms',
            self::ReadyToBook => 'wants to go ahead, accept the estimate or schedule the work',
            self::WantsCallback => 'asks to be called or to speak with someone',
            self::NotInterested => 'declines, chose someone else, or asks to stop',
            self::NeedsMoreInformation => 'undecided and waiting on something or needs more time or details',
            self::WrongNumber => 'says they are the wrong person or never requested this',
            self::Complaint => 'expresses dissatisfaction with service, delays or the business',
            self::Spam => 'automated, irrelevant or promotional content (including auto-replies)',
            self::Unclear => 'intent cannot be determined',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
