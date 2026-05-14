import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { AgendaApiService } from './agenda-api.service';
import { AppComponent } from './app.component';

describe('AppComponent', () => {
  let agendaApiSpy: jasmine.SpyObj<AgendaApiService>;

  beforeEach(async () => {
    agendaApiSpy = jasmine.createSpyObj<AgendaApiService>('AgendaApiService', [
      'getSessionStatus',
      'getPublicProfile',
      'login',
      'register',
      'logout',
      'updateProfileVisibility',
      'getActivities',
      'createActivity',
      'updateActivity',
      'deleteActivity',
      'getGeneralPendings',
      'createGeneralPending',
      'updateGeneralPending',
      'deleteGeneralPending',
      'getFinancialEntries',
      'createFinancialEntry',
      'updateFinancialEntry',
      'deleteFinancialEntry'
    ]);

    agendaApiSpy.getSessionStatus.and.returnValue(
      of({
        authenticated: true,
        user: {
          id: 1,
          name: 'Cristian',
          username: 'cristian',
          profilePublic: false,
          publicUrl: 'https://agenda.steelsoft.com.co/cristian'
        },
        canRegister: false
      })
    );
    agendaApiSpy.getPublicProfile.and.returnValue(
      of({
        found: true,
        profileEnabled: true,
        user: {
          name: 'Cristian',
          username: 'cristian',
          publicUrl: 'https://agenda.steelsoft.com.co/cristian'
        },
        activities: []
      })
    );
    agendaApiSpy.login.and.returnValue(
      of({
        authenticated: true,
        user: {
          id: 1,
          name: 'Cristian',
          username: 'cristian',
          profilePublic: false,
          publicUrl: 'https://agenda.steelsoft.com.co/cristian'
        },
        canRegister: false
      })
    );
    agendaApiSpy.register.and.returnValue(
      of({
        authenticated: true,
        user: {
          id: 1,
          name: 'Cristian',
          username: 'cristian',
          profilePublic: false,
          publicUrl: 'https://agenda.steelsoft.com.co/cristian'
        },
        canRegister: false
      })
    );
    agendaApiSpy.logout.and.returnValue(of({ success: true }));
    agendaApiSpy.updateProfileVisibility.and.returnValue(
      of({
        authenticated: true,
        user: {
          id: 1,
          name: 'Cristian',
          username: 'cristian',
          profilePublic: true,
          publicUrl: 'https://agenda.steelsoft.com.co/cristian'
        },
        canRegister: false
      })
    );
    agendaApiSpy.getActivities.and.returnValue(of([]));
    agendaApiSpy.getGeneralPendings.and.returnValue(of([]));
    agendaApiSpy.getFinancialEntries.and.returnValue(of([]));
    agendaApiSpy.createActivity.and.callFake((activity) => of({ id: 1, ...activity }));
    agendaApiSpy.updateActivity.and.callFake((id, activity) => of({ id, ...activity }));
    agendaApiSpy.deleteActivity.and.returnValue(of({ success: true }));
    agendaApiSpy.createGeneralPending.and.callFake((pending) => of({ id: 1, ...pending }));
    agendaApiSpy.updateGeneralPending.and.callFake((id, pending) => of({ id, ...pending }));
    agendaApiSpy.deleteGeneralPending.and.returnValue(of({ success: true }));
    agendaApiSpy.createFinancialEntry.and.callFake((entry) => of({ id: 1, ...entry }));
    agendaApiSpy.updateFinancialEntry.and.callFake((id, entry) => of({ id, ...entry }));
    agendaApiSpy.deleteFinancialEntry.and.returnValue(of({ success: true }));

    await TestBed.configureTestingModule({
      imports: [AppComponent],
      providers: [{ provide: AgendaApiService, useValue: agendaApiSpy }]
    }).compileComponents();
  });

  it('should create the app', () => {
    const fixture = TestBed.createComponent(AppComponent);
    const app = fixture.componentInstance;
    expect(app).toBeTruthy();
  });

  it('should render the calendar header', () => {
    const fixture = TestBed.createComponent(AppComponent);
    fixture.detectChanges();
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.calendar-panel h2')?.textContent).toContain('Mayo 2026');
  });

  it('should render the calendar without sunday', () => {
    const fixture = TestBed.createComponent(AppComponent);
    fixture.detectChanges();
    const compiled = fixture.nativeElement as HTMLElement;
    const weekdayLabels = Array.from(compiled.querySelectorAll('.weekdays span')).map((element) =>
      element.textContent?.trim()
    );

    expect(weekdayLabels).toEqual(['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab']);
  });

  it('should load activities from the api', () => {
    agendaApiSpy.getActivities.and.returnValue(
      of([
        {
          id: 1,
          title: 'Reunion',
          startTime: '09:00',
          endTime: '09:30',
          assignee: 'Steelsoft',
          visibility: 'private',
          completed: false,
          location: '',
          description: '',
          date: '2026-05-05'
        }
      ])
    );

    const fixture = TestBed.createComponent(AppComponent);
    fixture.detectChanges();
    const app = fixture.componentInstance as unknown as {
      activities: Array<{ startTime: string; endTime: string }>;
    };

    expect(app.activities[0]).toEqual(
      jasmine.objectContaining({
        startTime: '09:00',
        endTime: '09:30'
      })
    );
  });

  it('should load general pendings from the api', () => {
    agendaApiSpy.getGeneralPendings.and.returnValue(
      of([
        {
          id: 2,
          title: 'Llamar proveedor',
          assignee: 'Julian',
          description: 'Pendiente con fecha',
          date: '2026-05-06'
        }
      ])
    );

    const fixture = TestBed.createComponent(AppComponent);
    fixture.detectChanges();
    const app = fixture.componentInstance as unknown as {
      generalPendings: Array<{ title: string; assignee: string }>;
    };

    expect(app.generalPendings[0]).toEqual(
      jasmine.objectContaining({
        title: 'Llamar proveedor',
        assignee: 'Julian'
      })
    );
  });

  it('should load financial entries from the api', () => {
    agendaApiSpy.getFinancialEntries.and.returnValue(
      of([
        {
          id: 4,
          title: 'Pago mensual',
          type: 'income',
          amount: 800000,
          assignee: 'Steelsoft',
          description: 'Cliente recurrente',
          date: '2026-05-06'
        }
      ])
    );

    const fixture = TestBed.createComponent(AppComponent);
    fixture.detectChanges();
    const app = fixture.componentInstance as unknown as {
      financialEntries: Array<{ title: string; amount: number; type: string }>;
    };

    expect(app.financialEntries[0]).toEqual(
      jasmine.objectContaining({
        title: 'Pago mensual',
        amount: 800000,
        type: 'income'
      })
    );
  });

  it('should load an activity into the form when editing', () => {
    const fixture = TestBed.createComponent(AppComponent);
    const app = fixture.componentInstance as unknown as {
      activities: Array<{
        id: number;
        title: string;
        startTime: string;
        endTime: string;
        assignee: string;
        visibility: 'private' | 'public';
        completed: boolean;
        location: string;
        description: string;
        date: string;
      }>;
      editActivity: (activity: {
        id: number;
        title: string;
        startTime: string;
        endTime: string;
        assignee: string;
        visibility: 'private' | 'public';
        completed: boolean;
        location: string;
        description: string;
        date: string;
      }) => void;
      newActivity: { title: string; startTime: string; endTime: string; visibility: 'private' | 'public' };
      editingActivityId: number | null;
      isActivityPanelOpen: boolean;
    };

    app.activities = [
      {
        id: 99,
        title: 'Demo cliente',
        startTime: '11:00',
        endTime: '11:30',
        assignee: 'Julian',
        visibility: 'private',
        completed: false,
        location: 'Oficina',
        description: 'Presentacion',
        date: '2026-05-05'
      }
    ];

    app.editActivity(app.activities[0]);

    expect(app.editingActivityId).toBe(99);
    expect(app.isActivityPanelOpen).toBeTrue();
    expect(app.newActivity.title).toBe('Demo cliente');
    expect(app.newActivity.startTime).toBe('11:00');
    expect(app.newActivity.endTime).toBe('11:30');
  });

  it('should open the activity panel for the selected date', () => {
    const fixture = TestBed.createComponent(AppComponent);
    const app = fixture.componentInstance as unknown as {
      selectedDate: string;
      isActivityPanelOpen: boolean;
      openActivityPanel: (isoDate: string) => void;
    };

    app.openActivityPanel('2026-05-12');

    expect(app.selectedDate).toBe('2026-05-12');
    expect(app.isActivityPanelOpen).toBeTrue();
  });

  it('should open the pending panel', () => {
    const fixture = TestBed.createComponent(AppComponent);
    const app = fixture.componentInstance as unknown as {
      isPendingPanelOpen: boolean;
      openPendingPanel: () => void;
    };

    app.openPendingPanel();

    expect(app.isPendingPanelOpen).toBeTrue();
  });

  it('should open the finance panel', () => {
    const fixture = TestBed.createComponent(AppComponent);
    const app = fixture.componentInstance as unknown as {
      isFinancePanelOpen: boolean;
      openFinancePanel: () => void;
    };

    app.openFinancePanel();

    expect(app.isFinancePanelOpen).toBeTrue();
  });

  it('should group half-hour activities into the same weekly hour slot', () => {
    const fixture = TestBed.createComponent(AppComponent);
    const app = fixture.componentInstance as unknown as {
      activities: Array<{
        id: number;
        title: string;
        startTime: string;
        endTime: string;
        assignee: string;
        visibility: 'private' | 'public';
        completed: boolean;
        location: string;
        description: string;
        date: string;
      }>;
      getActivitiesForWeekHourSlot: (isoDate: string, time: string) => Array<{ id: number }>;
    };

    app.activities = [
      {
        id: 7,
        title: 'Llamada',
        startTime: '09:30',
        endTime: '10:00',
        assignee: 'Julian',
        visibility: 'private',
        completed: false,
        location: '',
        description: '',
        date: '2026-05-05'
      }
    ];

    expect(app.getActivitiesForWeekHourSlot('2026-05-05', '09:00')).toEqual([
      jasmine.objectContaining({ id: 7 })
    ]);
  });

  it('should load a financial entry into the form when editing', () => {
    const fixture = TestBed.createComponent(AppComponent);
    const app = fixture.componentInstance as unknown as {
      financialEntries: Array<{
        id: number;
        title: string;
        type: 'income' | 'expense';
        amount: number;
        assignee: string;
        description: string;
        date: string;
      }>;
      editFinancialEntry: (entry: {
        id: number;
        title: string;
        type: 'income' | 'expense';
        amount: number;
        assignee: string;
        description: string;
        date: string;
      }) => void;
      newFinancialEntry: { title: string; type: string; amount: number; date: string };
      editingFinancialEntryId: number | null;
      isFinancePanelOpen: boolean;
    };

    app.financialEntries = [
      {
        id: 50,
        title: 'Compra dominio',
        type: 'expense',
        amount: 120000,
        assignee: 'Julian',
        description: 'Renovacion anual',
        date: '2026-05-06'
      }
    ];

    app.editFinancialEntry(app.financialEntries[0]);

    expect(app.editingFinancialEntryId).toBe(50);
    expect(app.isFinancePanelOpen).toBeTrue();
    expect(app.newFinancialEntry.title).toBe('Compra dominio');
    expect(app.newFinancialEntry.type).toBe('expense');
    expect(app.newFinancialEntry.amount).toBe(120000);
    expect(app.newFinancialEntry.date).toBe('2026-05-06');
  });

  it('should keep working when a saved financial entry returns without a normalized date', () => {
    const savedEntry = {
      id: 88,
      title: 'Abono parcial',
      type: 'income' as const,
      amount: 50000,
      assignee: 'Steelsoft',
      description: 'Pago recibido',
      date: undefined as unknown as string
    };

    agendaApiSpy.updateFinancialEntry.and.returnValue(of(savedEntry));

    const fixture = TestBed.createComponent(AppComponent);
    const app = fixture.componentInstance as unknown as {
      financialEntries: Array<{
        id: number;
        title: string;
        type: 'income' | 'expense';
        amount: number;
        assignee: string;
        description: string;
        date?: string;
      }>;
      editingFinancialEntryId: number | null;
      newFinancialEntry: {
        title: string;
        type: 'income' | 'expense';
        amount: number;
        assignee: string;
        description: string;
        date: string;
      };
      addFinancialEntry: () => void;
    };

    app.financialEntries = [savedEntry];
    app.editingFinancialEntryId = 88;
    app.newFinancialEntry = {
      title: 'Abono parcial',
      type: 'income',
      amount: 50000,
      assignee: 'Steelsoft',
      description: 'Pago recibido',
      date: '2026-05-06'
    };

    expect(() => app.addFinancialEntry()).not.toThrow();
  });
});
