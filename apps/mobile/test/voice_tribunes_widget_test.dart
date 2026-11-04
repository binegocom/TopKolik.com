import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:topkolik_mobile/core/models/topkolik_domain_models.dart';
import 'package:topkolik_mobile/features/voice_tribunes/presentation/widgets/voice_tribune_view.dart';

void main() {
  testWidgets('VoiceTribuneView renders active room stage, equalizer, controls, and room cards', (WidgetTester tester) async {
    tester.view.physicalSize = const Size(1170, 2532);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(() => tester.view.resetPhysicalSize());

    final rooms = [
      const VoiceTribuneRoom(
        id: 1,
        fixtureId: 1,
        title: 'Derbi 2. Yarı Taktik ve Hakem Analizi',
        phaseTag: 'MAÇ ANINDA CANLI',
        listenersCount: 1200,
        clubId: 1,
        clubName: 'Galatasaray',
        hostName: 'Barış Lider',
        isJoined: true,
        isMicMuted: true,
      ),
      const VoiceTribuneRoom(
        id: 2,
        fixtureId: 1,
        title: 'Fenerbahçe Deplasman Tribünü',
        phaseTag: 'MAÇ ANINDA CANLI',
        listenersCount: 850,
        clubId: 2,
        clubName: 'Fenerbahçe',
        hostName: 'Kadıköy',
        isJoined: false,
        isMicMuted: true,
      ),
    ];

    String? actionTriggered;

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: VoiceTribuneView(
            rooms: rooms,
            onCreateRoom: (title, phase) {},
            onTriggerAction: (roomId, action, emoji) {
              actionTriggered = action;
            },
          ),
        ),
      ),
    );

    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('🎙️ SESLİ TRİBÜN MEYDANI'), findsOneWidget);
    expect(find.text('Derbi 2. Yarı Taktik ve Hakem Analizi'), findsWidgets);
    expect(find.text('🎧 1200 Dinleyici'), findsOneWidget);
    expect(find.text('🎙️ Mikrofonu Aç'), findsOneWidget);
    expect(find.text('✋ Söz İste'), findsOneWidget);

    // Tap mic toggle
    await tester.tap(find.text('🎙️ Mikrofonu Aç'));
    await tester.pump();
    expect(actionTriggered, 'toggle-mic');

    // Tap hand raise
    await tester.tap(find.text('✋ Söz İste'));
    await tester.pump();
    expect(actionTriggered, 'toggle-hand');
  });
}
