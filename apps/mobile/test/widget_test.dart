import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:topkolik_mobile/main.dart';

void main() {
  testWidgets('TopkolikApp smoke test - renders brand header and navigation items', (WidgetTester tester) async {
    // Set a phone portrait size for testing
    tester.view.physicalSize = const Size(1170, 2532);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(() => tester.view.resetPhysicalSize());

    await tester.pumpWidget(const TopkolikApp());
    // Pump frames to complete async initialization without hanging on infinite repeating animation
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    // Verify brand header
    expect(find.text('TOPKOLİK'), findsOneWidget);
    expect(find.text('.COM'), findsOneWidget);

    // Verify live scoreboard
    expect(find.text('Galatasaray'), findsWidgets);
    expect(find.text('Fenerbahçe'), findsWidgets);
    expect(find.text('2 - 1'), findsOneWidget);

    // Verify bottom navigation items
    expect(find.text('Maç Arenası'), findsOneWidget);
    expect(find.text('Sesli Tribün'), findsOneWidget);
    expect(find.text('Taktik Tahta'), findsOneWidget);
    expect(find.text('Tahminler'), findsOneWidget);
    expect(find.text('Taraftar Ligi'), findsOneWidget);
  });
}
