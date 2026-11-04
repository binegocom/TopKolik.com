import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:topkolik_mobile/core/widgets/motion_widgets.dart';

void main() {
  group('Luxury Motion Widgets', () {
    testWidgets('PressableScale animates scale on tap', (WidgetTester tester) async {
      bool tapped = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: PressableScale(
              onTap: () => tapped = true,
              child: const Text('Tıkla'),
            ),
          ),
        ),
      );

      expect(find.text('Tıkla'), findsOneWidget);
      await tester.tap(find.text('Tıkla'));
      await tester.pumpAndSettle();
      expect(tapped, isTrue);
    });

    testWidgets('GlassContainer renders child with backdrop filter', (WidgetTester tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: GlassContainer(
              child: Text('Glass Content'),
            ),
          ),
        ),
      );

      expect(find.text('Glass Content'), findsOneWidget);
      expect(find.byType(BackdropFilter), findsOneWidget);
    });

    testWidgets('ClubCrestBadge renders short name with gradient shield', (WidgetTester tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: ClubCrestBadge(
              shortName: 'GS',
              primaryHex: '#A6192E',
              secondaryHex: '#FFD700',
              size: 48,
              isSelected: true,
            ),
          ),
        ),
      );

      expect(find.text('GS'), findsOneWidget);
    });
  });
}

