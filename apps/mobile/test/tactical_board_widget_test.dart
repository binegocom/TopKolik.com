import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:topkolik_mobile/core/models/topkolik_domain_models.dart';
import 'package:topkolik_mobile/features/tactical_board/presentation/widgets/tactical_pitch_board_view.dart';

void main() {
  testWidgets('TacticalPitchBoardView renders pitch, formations, starters, bench, and handles substitutions', (WidgetTester tester) async {
    tester.view.physicalSize = const Size(1170, 2532);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(() => tester.view.resetPhysicalSize());

    const lineup = TacticalLineup(
      formation: '4-2-3-1',
      mentality: 'Önde Şok Baskı',
      coachName: 'Okan Buruk',
      starters: [
        TacticalPlayerSlot(slotIndex: 0, playerName: 'Muslera', shirtNumber: 1, position: 'GK', xRatio: 0.50, yRatio: 0.88, clubPrimaryHex: '#A6192E'),
        TacticalPlayerSlot(slotIndex: 1, playerName: 'Icardi', shirtNumber: 9, position: 'FW', xRatio: 0.50, yRatio: 0.15, clubPrimaryHex: '#A6192E'),
      ],
      bench: [
        TacticalPlayerSlot(slotIndex: 10, playerName: 'Batshuayi', shirtNumber: 44, position: 'FW', xRatio: 0.50, yRatio: 0.15, clubPrimaryHex: '#A6192E'),
      ],
    );

    String? changedFormation;
    String? changedMentality;
    int? subbedStarter;
    int? subbedBench;

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TacticalPitchBoardView(
            lineup: lineup,
            onFormationChanged: (form) => changedFormation = form,
            onMentalityChanged: (ment) => changedMentality = ment,
            onPlayerSubstituted: (s, b) {
              subbedStarter = s;
              subbedBench = b;
            },
          ),
        ),
      ),
    );

    await tester.pump();

    expect(find.text('İLK 11 TAKTİK TAHTASI & KADRO KURUCU'), findsOneWidget);
    expect(find.text('4-2-3-1'), findsOneWidget);
    expect(find.text('4-3-3'), findsOneWidget);
    expect(find.text('3-5-2'), findsOneWidget);
    expect(find.text('⚡ Önde Şok Baskı'), findsOneWidget);

    // Starters and bench
    expect(find.text('Muslera'), findsOneWidget);
    expect(find.text('Icardi'), findsOneWidget);
    expect(find.text('#44 Batshuayi'), findsOneWidget);

    // Switch formation
    await tester.tap(find.text('4-3-3'));
    await tester.pump(const Duration(milliseconds: 200));
    expect(changedFormation, '4-3-3');

    // Tap mentality
    await tester.tap(find.text('⚡ Önde Şok Baskı'));
    await tester.pump(const Duration(milliseconds: 200));
    expect(changedMentality, isNotNull);

    // Perform substitution: tap Icardi then Batshuayi
    await tester.tap(find.text('Icardi'));
    await tester.pump(const Duration(milliseconds: 200));
    expect(find.text('⚡ Değiştirmek için 2. oyuncuya dokun'), findsOneWidget);

    await tester.drag(find.byType(ListView).first, const Offset(0, -300));
    await tester.pump(const Duration(milliseconds: 200));

    await tester.tap(find.text('#44 Batshuayi'));
    await tester.pump(const Duration(milliseconds: 200));
    expect(subbedStarter, 1);
    expect(subbedBench, 10);
  });
}
