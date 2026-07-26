<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test player pool: 50 real, currently-active US Chess members with real
 * USCF IDs and their real current Regular ratings. Used to generate test
 * rosters and test registrants for exercising a tournament end to end
 * without needing real ticket sales.
 *
 * Lives in Tournament Manager rather than wp-etr (moved 2026-07-21) because
 * it is tournament-testing data, not registration plumbing. wp-etr's "Add
 * test registrants" tool reads it from here via
 * WPMTM_Test_Players::all() when TM is active. The attendee-writing itself
 * deliberately stayed in wp-etr: TM reads Event Tickets and ETECF data but
 * does not write it, and README states plainly that TM never creates
 * registrations.
 *
 * Sourced originally from the USCF ratings API's ranked members endpoint,
 * which surfaces the country's highest-rated tournament players, and every
 * id/rating pair was verified individually against `GET /members/{id}`.
 * Re-audited in full 2026-07-21 against the live API: all 50 returned
 * status "Active" with a Regular rating present, none returned "Deceased"
 * or any other status, and every stored rating still matched USCF exactly.
 *
 * These are public competitive players. Never put the club's own affiliate
 * ID, its TD member IDs, or any ordinary member's record in here - those
 * are PII and belong in stored settings read at runtime. If this list is
 * ever extended, every new id must come from a real API response, never
 * from a guess, or the pool stops being usable for testing the USCF
 * validation path it exists to exercise.
 *
 * @return array<int, array{last:string,first:string,rating:int,uscf_id:string}>
 */

return array(
	array( 'last' => 'Kasparov', 'first' => 'Garry', 'rating' => 2868, 'uscf_id' => '12518524' ),
	array( 'last' => 'Caruana', 'first' => 'Fabiano', 'rating' => 2865, 'uscf_id' => '12743305' ),
	array( 'last' => 'Nakamura', 'first' => 'Hikaru', 'rating' => 2849, 'uscf_id' => '12641216' ),
	array( 'last' => 'So', 'first' => 'Wesley', 'rating' => 2821, 'uscf_id' => '13145890' ),
	array( 'last' => 'Liang', 'first' => 'Awonder', 'rating' => 2802, 'uscf_id' => '13999045' ),
	array( 'last' => 'Le', 'first' => 'Liem Quang', 'rating' => 2799, 'uscf_id' => '14744920' ),
	array( 'last' => 'Niemann', 'first' => 'Hans', 'rating' => 2799, 'uscf_id' => '15041466' ),
	array( 'last' => 'Aronian', 'first' => 'Levon', 'rating' => 2795, 'uscf_id' => '15218444' ),
	array( 'last' => 'Dominguez Perez', 'first' => 'Leinier', 'rating' => 2782, 'uscf_id' => '14744935' ),
	array( 'last' => 'Sevian', 'first' => 'Samuel', 'rating' => 2765, 'uscf_id' => '13493815' ),
	array( 'last' => 'Woodward', 'first' => 'Andy Austin', 'rating' => 2732, 'uscf_id' => '16302012' ),
	array( 'last' => 'Xiong', 'first' => 'Jeffery', 'rating' => 2730, 'uscf_id' => '13648621' ),
	array( 'last' => 'Mishra', 'first' => 'Abhimanyu', 'rating' => 2719, 'uscf_id' => '15456104' ),
	array( 'last' => 'Robson', 'first' => 'Ray', 'rating' => 2717, 'uscf_id' => '12847250' ),
	array( 'last' => 'Shankland', 'first' => 'Sam', 'rating' => 2713, 'uscf_id' => '12852765' ),
	array( 'last' => 'Oparin', 'first' => 'Grigoriy', 'rating' => 2709, 'uscf_id' => '17084025' ),
	array( 'last' => 'Onischuk', 'first' => 'Alex', 'rating' => 2707, 'uscf_id' => '12625186' ),
	array( 'last' => 'Khanin', 'first' => 'Semen', 'rating' => 2701, 'uscf_id' => '17346301' ),
	array( 'last' => 'Izoria', 'first' => 'Zviad', 'rating' => 2694, 'uscf_id' => '12922861' ),
	array( 'last' => 'Zherebukh', 'first' => 'Yaroslav', 'rating' => 2689, 'uscf_id' => '15105277' ),
	array( 'last' => 'Zhou', 'first' => 'Jianchao', 'rating' => 2687, 'uscf_id' => '15524414' ),
	array( 'last' => 'Antipov', 'first' => 'Mikhail', 'rating' => 2686, 'uscf_id' => '30034391' ),
	array( 'last' => 'Bortnyk', 'first' => 'Olexandr', 'rating' => 2680, 'uscf_id' => '16754590' ),
	array( 'last' => 'Seirawan', 'first' => 'Yasser', 'rating' => 2677, 'uscf_id' => '10509459' ),
	array( 'last' => 'Hess', 'first' => 'Robert L', 'rating' => 2677, 'uscf_id' => '12749774' ),
	array( 'last' => 'Kamsky', 'first' => 'Gata', 'rating' => 2674, 'uscf_id' => '12528459' ),
	array( 'last' => 'Hong', 'first' => 'Andrew', 'rating' => 2674, 'uscf_id' => '14941904' ),
	array( 'last' => 'Jacobson', 'first' => 'Brandon', 'rating' => 2667, 'uscf_id' => '14160065' ),
	array( 'last' => 'Yoo', 'first' => 'Christopher Woojin', 'rating' => 2665, 'uscf_id' => '15244943' ),
	array( 'last' => 'Quesada Perez', 'first' => 'Yuniesky', 'rating' => 2664, 'uscf_id' => '15197865' ),
	array( 'last' => 'Petrosian', 'first' => 'Tigran L', 'rating' => 2662, 'uscf_id' => '13981324' ),
	array( 'last' => 'Lomasov', 'first' => 'Semyon', 'rating' => 2662, 'uscf_id' => '31006010' ),
	array( 'last' => 'Avrukh', 'first' => 'Boris', 'rating' => 2661, 'uscf_id' => '15478832' ),
	array( 'last' => 'Gurevich', 'first' => 'Ilya', 'rating' => 2660, 'uscf_id' => '12256450' ),
	array( 'last' => 'Swiercz', 'first' => 'Dariusz', 'rating' => 2660, 'uscf_id' => '16113717' ),
	array( 'last' => 'Shulman', 'first' => 'Yury', 'rating' => 2649, 'uscf_id' => '12741541' ),
	array( 'last' => 'Burke', 'first' => 'John Michael', 'rating' => 2648, 'uscf_id' => '14069596' ),
	array( 'last' => 'Tang', 'first' => 'Andrew', 'rating' => 2646, 'uscf_id' => '13215196' ),
	array( 'last' => 'Friedel', 'first' => 'Joshua E', 'rating' => 2630, 'uscf_id' => '12593986' ),
	array( 'last' => 'Christiansen', 'first' => 'Larry M', 'rating' => 2628, 'uscf_id' => '10460921' ),
	array( 'last' => 'Li', 'first' => 'Ruifeng', 'rating' => 2625, 'uscf_id' => '13768313' ),
	array( 'last' => 'Rambaldi', 'first' => 'Francesco', 'rating' => 2625, 'uscf_id' => '13962544' ),
	array( 'last' => 'Ghazarian', 'first' => 'Kirk', 'rating' => 2620, 'uscf_id' => '14885268' ),
	array( 'last' => 'Bruzon Batista', 'first' => 'Lazaro', 'rating' => 2619, 'uscf_id' => '15199286' ),
	array( 'last' => 'Schwartzman', 'first' => 'Gabriel', 'rating' => 2617, 'uscf_id' => '12514568' ),
	array( 'last' => 'Wang', 'first' => 'Justin', 'rating' => 2615, 'uscf_id' => '14930904' ),
	array( 'last' => 'Baryshpolets', 'first' => 'Andrey', 'rating' => 2611, 'uscf_id' => '15835952' ),
	array( 'last' => 'Brown', 'first' => 'Michael W', 'rating' => 2610, 'uscf_id' => '13289023' ),
	array( 'last' => 'Alburt', 'first' => 'Lev', 'rating' => 2609, 'uscf_id' => '12078790' ),
	array( 'last' => 'Sonis', 'first' => 'Francesco', 'rating' => 2609, 'uscf_id' => '32511540' ),
);
